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

    $errors = [];
    $count = 0;
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
        $or = [];
        $accountContactParams = [
          'plugin' => $this->_plugin,
          'connector_id' => $params['connector_id'],
          'accounts_display_name' => $contact['Name'],
          'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($contact['UpdatedDateUTC'])),
          'accounts_contact_id' => $contact['ContactID'],
          'accounts_data' => json_encode($contact),
          'accounts_needs_update' => FALSE,
        ];

        // Xero sets ContactNumber = ContactID (accounts_contact_id) if not set by CiviCRM.
        // We can only use it if it is an integer (map it to CiviCRM contact_id).
        $contactID = CRM_Utils_Type::validate($contact['ContactNumber'] ?? NULL, 'Integer', FALSE);
        if ($contactID) {
          $accountContactParams['contact_id'] = $contactID;
          $or[] = ['contact_id', '=', $contactID];
        }

        $save = TRUE;
        CRM_Accountsync_Hook::accountPullPreSave('contact', $contact, $save, $accountContactParams);
        if (!$save) {
          continue;
        }

        $accountsContactID = $contact['ContactID'];
        $or[] = ['accounts_contact_id', '=', $accountsContactID];
        // Find accountContact records matching accounts_contact_id (Xero ContactID) or contact_id (Xero ContactNumber)
        $accountContacts = AccountContact::get(FALSE)
          ->addWhere('plugin', '=', $this->_plugin)
          ->addWhere('connector_id', '=', $accountContactParams['connector_id'])
          ->addClause('OR', $or)
          ->execute()
          ->indexBy('id');
        if ($accountContacts->count() === 1) {
          // We have exactly one match. Update existing
          $accountContactParams['id'] = $accountContacts->first()['id'];
        }
        elseif ($accountContacts->count() > 1) {
          // We found more than one matching record
          // Means we have duplicate contacts and Xero/Civi don't match up.
          $errorMessage = 'Duplicate records found for accounts_contact_id: ' . $accountsContactID . ', contact_id: ' . $contactID;
          AccountContact::update(FALSE)
            ->addWhere('id', 'IN', $accountContacts->column('id'))
            ->addValue('is_error_resolved', FALSE)
            ->addValue('error_data', json_encode([
              'error' => $errorMessage
            ]))
            ->addValue('accounts_data', json_encode($contact))
            ->execute();
          $errors[] = 'Duplicate records found for accounts_contact_id: ' . $accountsContactID . ', contact_id: ' . $contactID;
          // We recorded the error - now continue with syncing the rest
          continue;
        }

        // Check that the CiviCRM contact ID is valid.
        // If the CiviCRM contact ID does not exist but is set it was probably deleted in CiviCRM.
        if ($contactID) {
          $contact = Contact::get(FALSE)
            ->addWhere('id', '=', $contactID)
            ->execute()
            ->first();
          if (empty($contact)) {
            unset($accountContactParams['contact_id']);
          }
        }

        try {
          if ($accountContacts->count() === 0) {
            // Create new AccountContact record
            $newAccountContact = AccountContact::create(FALSE)
              ->setValues($accountContactParams)
              ->execute()
              ->first();
            $ids[] = $newAccountContact['id'];
          }
          else {
            // Update existing AccountInvoice record
            $modifiedFieldKeys = [
              'accounts_display_name',
              'accounts_modified_date',
              'accounts_contact_id',
              'accounts_needs_update',
              'accounts_data',
            ];
            foreach ($modifiedFieldKeys as $key) {
              // Every time we do an "update" last_sync_date is updated which triggers an entry in log_civicrm_account_contact.
              // So check if anything actually changed before updating.
              if ($accountContactParams[$key] !== $accountContacts->first()[$key]) {
                // Something changed, update AccountContact in DB
                $newAccountContact = AccountContact::update(FALSE)
                  ->setValues($accountContactParams)
                  ->addWhere('id', '=', $accountContacts->first()['id'])
                  ->execute()
                  ->first();
                $ids[] = $newAccountContact['id'];
                break;
              }
            }
          }
        }
        catch (CRM_Core_Exception $e) {
          $errors[] = E::ts('Failed to store %1 (%2)', [1 => $contact['Name'], 2 => $contact['ContactID']])
            . E::ts(' with error ') . $e->getMessage();
        }
      }
    }
    if ($errors) {
      // Since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(E::ts('Not all records were saved') . ': ' . print_r($errors, TRUE), 'incomplete', $errors);
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
          $contactIsDeleted = FALSE;
          if (!empty($matchingAccountContact['contact_id'])) {
            $contactIsDeleted = Contact::get(FALSE)
              ->addWhere('id', '=', $matchingAccountContact['contact_id'])
              ->addWhere('is_deleted', '=', TRUE)
              ->execute()
              ->first()['is_deleted'];
          }
          if (empty($matchingAccountContact['contact_id']) || $contactIsDeleted) {
            \Civi::log(E::SHORT_NAME)->error(E::ts('Error updating existing contact for %1', [1 => $record['contact_id']]));
            AccountContact::delete(FALSE)
              ->addWhere('id', '=', $record['id'])
              ->execute();
            $record['do_not_sync'] = 0;
            $record['id'] = $matchingAccountContact['id'];
          }
          elseif ($matchingAccountContact['contact_id'] != $record['contact_id']) {
            throw new CRM_Core_Exception(ts('Attempt to sync Contact %1 to Xero entry for existing Contact %2. ', [
              1 => $record['contact_id'],
              2 => $matchingAccountContact['contact_id'],
            ]), 'xero_dup_contact');
          }
        }

        $record['error_data'] = NULL;
        if (empty($record['accounts_contact_id'])) {
          $record['accounts_contact_id'] = $result['Contacts']['Contact']['ContactID'];
        }
        $record['accounts_modified_date'] = $result['Contacts']['Contact']['UpdatedDateUTC'];
        $record['accounts_data'] = json_encode($result['Contacts']['Contact']);
        $record['accounts_display_name'] = $result['Contacts']['Contact']['Name'];
        // This will update the last sync date.
        unset($record['last_sync_date']);
        AccountContact::update(FALSE)
          ->setValues($record)
          ->addValue('accounts_needs_update', FALSE)
          ->execute();
      }
      catch (CRM_Civixero_Exception_XeroThrottle $e) {
        throw new CRM_Core_Exception('Contact Push aborted due to throttling by Xero' . print_r($errors, TRUE));
      }
      catch (CRM_Core_Exception $e) {
        $errorMessage = E::ts('Failed to push contactID: %1') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
          . E::ts('Error: ') . $e->getMessage() . print_r($responseErrors ?? [], TRUE)
          . E::ts('Contact Push failed');

        AccountContact::update(FALSE)
          ->addWhere('id', '=', $record['id'])
          ->addValue('is_error_resolved', FALSE)
          ->addValue('error_data', json_encode([
            'error' => $e->getMessage(),
            'error_data' => $record['error_data']
          ]))
          ->addValue('accounts_data', json_encode($contact))
          ->execute();
        $errors[] = $errorMessage;
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
   * @param $accountsContactID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  protected function mapToAccounts(array $contact, $accountsContactID) {
    $new_contact = [
      'Name' => $contact['display_name'] . ' - ' . $contact['id'],
      'FirstName' => $contact['first_name'] ?? '',
      'LastName' => $contact['last_name'] ?? '',
      'EmailAddress' => CRM_Utils_Rule::email($contact['email']) ? $contact['email'] : '',
      'ContactNumber' => $contact['id'],
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
    if (!empty($accountsContactID)) {
      $new_contact['ContactID'] = $accountsContactID;
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
