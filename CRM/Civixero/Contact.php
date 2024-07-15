<?php

use CRM_Civixero_ExtensionUtil as E;
use Civi\Api4\AccountContact;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Api4\LocationType;

class CRM_Civixero_Contact extends CRM_Civixero_Base {

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *
   * @throws CRM_Core_Exception
   */
  public function pull(array $params): void {
    // If we specify a xero contact id (UUID) then we try to load ONLY that contact.
    $params['xero_contact_id'] = $params['xero_contact_id'] ?? FALSE;
    try {

      /** @noinspection PhpUndefinedMethodInspection */
      $result = $this
        ->getSingleton($params['connector_id'])
        ->Contacts($params['xero_contact_id'], $this->formatDateForXero($params['start_date']));
      if (!is_array($result)) {
        throw new CRM_Core_Exception('Sync Failed', 'xero_retrieve_failure', (array) $result);
      }
      if (!empty($result['Contacts'])) {
        $contacts = $result['Contacts']['Contact'];
        if (isset($contacts['ContactID'])) {
          // the return syntax puts the contact only level higher up when only one contact is involved
          $contacts = [$contacts];
        }
        foreach ($contacts as $contact) {

          $save = TRUE;
          $params = [
            'accounts_display_name' => $contact['Name'],
            'contact_id' => $contact['ContactNumber'] ?? NULL,
            'accounts_modified_date' => $contact['UpdatedDateUTC'],
            'plugin' => 'xero',
            'accounts_contact_id' => $contact['ContactID'],
            'accounts_data' => json_encode($contact),
            'connector_id' => $params['connector_id'],
          ];
          CRM_Accountsync_Hook::accountPullPreSave('contact', $contact, $save, $params);
          if (!$save) {
            continue;
          }
          try {
            $params['id'] = AccountContact::get(FALSE)
              ->addSelect('id')
              ->addWhere('accounts_contact_id', '=', $contact['ContactID'])
              ->addWhere('plugin', '=', $this->_plugin)
              ->execute()->first()['id'];
          }
          catch (CRM_Core_Exception $e) {
          }
          try {
            AccountContact::save(FALSE)->setRecords([$params])->execute();
          }
          catch (CRM_Core_Exception $e) {
            CRM_Core_Session::setStatus(E::ts('Failed to store ') . $params['accounts_display_name']
              . E::ts(' with error ') . $e->getMessage(),
              E::ts('Contact Pull failed'));
          }
        }
      }
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      throw new CRM_Core_Exception('Contact Pull aborted due to throttling by Xero');
    }
  }

  /**
   * Push contacts to Xero from the civicrm_account_contact with 'needs_update' = 1.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *  - start_date
   *
   * @return bool
   * @throws CRM_Core_Exception
   */
  public function push(array $params, int $limit = 10): bool {
    $records = $this->getContactsRequiringPushUpdate($params, $limit);
    if (empty($records)) {
      return TRUE;
    }
    $errors = [];

    foreach ($records as $record) {
      try {
        // Get the contact data.
        $contact = Contact::get(FALSE)
          ->addWhere('id', '=', $record['contact_id'])
          ->execute()
          ->first();
        if ($contact['is_deleted']) {
          AccountContact::update(FALSE)
            ->addWhere('id', '=', $record['id'])
            ->addValue('do_not_sync', TRUE)
            ->execute();
          continue;
        }

        // See if we have an email for the preferred location type?
        $locationTypeToSync = (int) Civi::settings()->get('xero_sync_location_type');
        $contact['email'] = $this->getPreferredEmail($locationTypeToSync, $record['contact_id']);
        $contact['phone'] = $this->getPreferredPhone($locationTypeToSync, $record['contact_id']);
        $contactAddress = $this->getPreferredAddress($locationTypeToSync, $record['contact_id']);
        if ($contactAddress) {
          $contact = array_merge($contact, $contactAddress);
        }

        $accountsContactID = !empty($record['accounts_contact_id']) ? $record['accounts_contact_id'] : NULL;
        $accountsContact = $this->mapToAccounts($contact, $accountsContactID);
        if ($accountsContact === FALSE) {
          $result = FALSE;
          $responseErrors = [];
        }
        else {
          /** @noinspection PhpUndefinedMethodInspection */
          $result = $this->getSingleton($params['connector_id'])->Contacts($accountsContact);
          $responseErrors = $this->validateResponse($result);
        }
        if ($result === FALSE) {
          unset($record['accounts_modified_date']);
        }
        if ($responseErrors) {
          $record['error_data'] = json_encode($responseErrors);
          throw new CRM_Core_Exception('Error in response from Xero');
        }

        /* When Xero returns an ID that matches an existing account_contact, update it instead. */
        $matchingAccountContact = AccountContact::get(FALSE)
          ->addWhere('accounts_contact_id', '=', $result['Contacts']['Contact']['ContactID'])
          ->addWhere('plugin', '=', $this->_plugin)
          ->addWhere('connector_id', '=', $params['connector_id'])
          ->execute()->first() ?? [];

        if (count($matchingAccountContact)) {
          if (empty($matchingAccountContact['contact_id']) ||
            civicrm_api3('contact', 'getvalue', ['id' => $matchingAccountContact['contact_id'], 'return' => 'contact_is_deleted'])) {
            Civi::log('civixero')->error(E::ts('Updating existing contact for %1', [1 => $record['contact_id']]));
            civicrm_api3('AccountContact', 'delete', ['id' => $record['id']]);
            $record['do_not_sync'] = 0;
            $record['id'] = $matchingAccountContact['id'];
          }
          elseif ($matchingAccountContact['contact_id'] !== $record['contact_id']) {
            throw new CRM_Core_Exception(E::ts('Attempt to sync Contact %1 to Xero entry for existing Contact %2. ', [
              1 => $record['contact_id'],
              2 => $matchingAccountContact['contact_id'],
            ]), 'xero_dup_contact');
          }
        }

        $record['error_data'] = 'null';
        if (empty($record['accounts_contact_id'])) {
          $record['accounts_contact_id'] = $result['Contacts']['Contact']['ContactID'];
        }
        $record['accounts_modified_date'] = $result['Contacts']['Contact']['UpdatedDateUTC'];
        $record['accounts_data'] = json_encode($result['Contacts']['Contact']);
        $record['accounts_display_name'] = $result['Contacts']['Contact']['Name'];
        // This will update the last sync date.
        $record['accounts_needs_update'] = 0;
        unset($record['last_sync_date']);
        civicrm_api3('AccountContact', 'create', $record);
      }
      catch (CRM_Civixero_Exception_XeroThrottle $e) {
        throw new CRM_Core_Exception('Contact Push aborted due to throttling by Xero' . print_r($errors, TRUE));
      }
      catch (CRM_Core_Exception $e) {
        $errors[] = E::ts('Failed to push ') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
          . E::ts(' with error ') . $e->getMessage() . print_r($responseErrors ?? [], TRUE)
          . E::ts('Contact Push failed');
      }
    }
    if ($errors) {
      // since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(E::ts('Not all contacts were saved') . print_r($errors, TRUE), 'incomplete', $errors);
    }
    return TRUE;
  }

  /**
   * Get the preferred email, taking the preferred location type into account.
   *
   * @param int $locationTypeToSync
   * @param int $contactID
   *
   * @return string|null
   * @throws \CRM_Core_Exception
   */
  private function getPreferredEmail(int $locationTypeToSync, int $contactID): ?string {
    if ($locationTypeToSync !== 0) {
      $email = Email::get(FALSE)
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('location_type_id', '=', $locationTypeToSync)
        ->execute()
        ->first();
    }
    if (empty($email)) {
      // Get the primary email
      $email = Email::get(FALSE)
        ->addWhere('is_primary', '=', TRUE)
        ->addWhere('contact_id', '=', $contactID)
        ->execute()
        ->first();
    }

    if (!empty($email['email'])) {
      // Yes, we have an email with preferred location type
      return $email['email'];
    }
    return NULL;
  }

  /**
   * Get the preferred phone, taking the preferred location type into account.
   *
   * @param int $locationTypeToSync
   * @param int $contactID
   *
   * @return mixed|null
   * @throws \CRM_Core_Exception
   */
  private function getPreferredPhone(int $locationTypeToSync, int $contactID) {
    if ($locationTypeToSync !== 0) {
      $phone = Phone::get(FALSE)
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('location_type_id', '=', $locationTypeToSync)
        ->execute()
        ->first();
    }
    if (empty($phone)) {
      // Get the primary phone
      $phone = Phone::get(FALSE)
        ->addWhere('is_primary', '=', TRUE)
        ->addWhere('contact_id', '=', $contactID)
        ->execute()
        ->first();
    }
    if (!empty($phone['phone'])) {
      // Yes, we have a phone with preferred location type.
      return $phone['phone'];
    }
    return NULL;
  }

  /**
   * Get the preferred address, taking the preferred location type into account.
   *
   * @param int $locationTypeToSync
   * @param int $contactID
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  private function getPreferredAddress(int $locationTypeToSync, int $contactID): ?array {
    if ($locationTypeToSync !== 0) {
      $address = Address::get(FALSE)
        ->addSelect('*', 'country_id:label', 'state_province_id:label')
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('location_type_id', '=', $locationTypeToSync)
        ->execute()
        ->first();
    }
    if (empty($address)) {
      // Get the primary address
      $address = Address::get(FALSE)
        ->addSelect('*', 'country_id:label', 'state_province_id:label')
        ->addWhere('is_primary', '=', TRUE)
        ->addWhere('contact_id', '=', $contactID)
        ->execute()
        ->first();
    }
    if (!empty($address['street_address'])) {
      // Yes, we have an address with preferred location type.
      return [
        'street_address' => $address['street_address'],
        'city' => $address['city'],
        'postal_code' => $address['postal_code'],
        'supplemental_address_1' => $address['supplemental_address_1'],
        'supplemental_address_2' => $address['supplemental_address_2'],
        'supplemental_address_3' => $address['supplemental_address_3'],
        'country' => $address['country_id:label'],
        'state_province_name' => $address['state_province_id:label'],
      ];
    }
    return NULL;
  }

  /**
   * Get contacts marked as needing to be pushed to the accounts package.
   *
   * @param array $params
   * @param int $limit
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContactsRequiringPushUpdate(array $params, int $limit): array {
    $accountContacts = AccountContact::get(FALSE)
      ->addWhere('plugin', '=', $this->_plugin)
      ->addWhere('accounts_needs_update', '=', TRUE)
      ->addWhere('connector_id', '=', $params['connector_id'])
      ->setLimit($limit);

    // If we specified a CiviCRM contact ID just push that contact.
    if (!empty($params['contact_id'])) {
      $accountContacts->addWhere('contact_id', '=', $params['contact_id']);
    }
    else {
      $accountContacts->addWhere('accounts_needs_update', '=', TRUE);
      $accountContacts->addWhere('contact_id', 'IS NOT NULL');
    }
    $accountContacts->addOrderBy('error_data');

    return (array) $accountContacts->execute();
  }

  /**
   * Map civicrm Array to Accounts package field names.
   *
   * @param array $contact
   *          Contact Array as returned from API
   * @param $accountsID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  protected function mapToAccounts(array $contact, $accountsID) {
    $new_contact = [
      'Name' => $contact['display_name'] . ' - ' . $contact['contact_id'],
      'FirstName' => $contact['first_name'],
      'LastName' => $contact['last_name'],
      'EmailAddress' => CRM_Utils_Rule::email($contact['email']) ? $contact['email'] : '',
      'ContactNumber' => $contact['contact_id'],
      'Addresses' => [
        'Address' => [
          [
            'AddressType' => 'POBOX', // described in documentation as the default mailing address for invoices http://blog.xero.com/developer/api/types/#Addresses
            'AddressLine1' => $contact['street_address'],
            'City' => $contact['city'],
            'PostalCode' => $contact['postal_code'],
            'AddressLine2' => $contact['supplemental_address_1'] ?? '',
            'AddressLine3' => $contact['supplemental_address_2'] ?? '',
            'AddressLine4' => $contact['supplemental_address_3'] ?? '',
            'Country' => $contact['country'] ?? '',
            'Region' => $contact['state_province_name'] ?? '',
          ],
        ],
      ],
      'Phones' => [
        'Phone' => [
          'PhoneType' => 'DEFAULT',
          'PhoneNumber' => $contact['phone'],
        ],
      ],
    ];
    if (!empty($accountsID)) {
      $new_contact['ContactID'] = $accountsID;
    }
    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('contact', $contact, $proceed, $new_contact);
    $new_contact = [
      $new_contact,
    ];
    if (!$proceed) {
      return FALSE;
    }
    return $new_contact;
  }

  /**
   * Get available location types.
   *
   * This is called from the setting declaration.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   * @noinspection PhpRedundantDocCommentInspection
   */
  public static function getLocationTypes(): array {
    $locationTypes = LocationType::get(FALSE)
      ->addSelect('id', 'display_name')
      ->execute();
    $locTypes = [0 => E::ts('- Primary -')];
    foreach ($locationTypes as $locationType) {
      $locTypes[$locationType['id']] = $locationType['display_name'];
    }
    return $locTypes;
  }
}
