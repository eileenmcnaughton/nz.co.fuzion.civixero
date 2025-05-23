<?php

use CRM_Civixero_ExtensionUtil as E;
use Civi\Api4\AccountContact;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Api4\LocationType;

class CRM_Civixero_Contact extends CRM_Civixero_Base {

  public function pullFromXero(
    array $filters,
    bool $includeArchived,
    bool $summaryOnly,
    string $searchTerm,
    int $page,
    int $pageSize,
    string $ifModifiedSinceDateTime,
  ): array {
    $xeroTenantId = $this->getTenantID();
    $ifModifiedSince = new DateTime($ifModifiedSinceDateTime);
    $where = $filters['where'] ?? NULL;
    $order = "Name ASC";
    $ids = NULL; //$iDs = ["00000000-0000-0000-0000-000000000000"];

    try {
      $xeroContacts = $this->getAccountingApiInstance()
        ->getContacts($xeroTenantId, $ifModifiedSince, $where, $order, $ids, $page, $includeArchived, $summaryOnly, $searchTerm, $pageSize);
      foreach ($xeroContacts->getContacts() as $xeroContact) {
        /**
         * @var \XeroAPI\XeroPHP\Models\Accounting\Contact $xeroContact
         */
        foreach ($xeroContact::attributeMap() as $localName => $originalName) {
          $getter = 'get' . $originalName;
          switch ($localName) {
            case 'updated_date_utc':
              $dateGetter = $getter . 'AsDate';
              $contact[$localName] = $xeroContact->$dateGetter()->format('Y-m-d H:i:s');
              break;

            default:
              $contact[$localName] = $xeroContact->$getter();
          }
        }
        $contacts[$contact['contact_id']] = $contact;
      }
    } catch (\InvalidArgumentException $e) {
      // This means there are no contacts returned for the requested page. That's ok!
      return [];
    } catch (\Exception $e) {
      \Civi::log('civixero')->error('Exception when calling AccountingApi->getContacts: ' . $e->getMessage());
      throw $e;
    }
    return $contacts;
  }

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *
   * @throws CRM_Core_Exception
   */
  public function pullUsingApi4(array $params): void {
    $page = 1;
    $pageSize = 100;

    try {
      while (TRUE) {
        $contactPull = \Civi\Api4\Xero::contactPull(FALSE)
          ->setIfModifiedSinceDateTime($params['start_date'])
          ->setConnectorID($params['connector_id'] ?? 0)
          ->setPage($page)
          ->setPageSize($pageSize);
        if (!empty($params['xero_contact_id'])) {
          $contactPull->setSearchTerm($params['xero_contact_id']);
        }
        $contacts = $contactPull->execute()->getArrayCopy();
        if (empty($contacts)) {
          break;
        }
        $this->processPull($contacts, $params['connector_id'] ?? 0);
        unset($contacts);
        $page++;
      }
    }
    catch (\Throwable $e) {
      \Civi::log('civixero')->error('CiviXero: Error when running Contact Pull: ' . $e->getMessage());
    }
  }

  private function processPull($contacts, int $connectorID) {
    $errors = $ids = [];

    foreach ($contacts as $xeroContactID => $xeroContact) {
      $or = [];
      $accountContactParams = [
        'plugin' => $this->_plugin,
        'connector_id' => $connectorID,
        'accounts_display_name' => $xeroContact['name'],
        'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($xeroContact['updated_date_utc'])),
        'accounts_contact_id' => $xeroContact['contact_id'],
        'accounts_data' => json_encode($xeroContact),
        'accounts_needs_update' => FALSE,
      ];

      // Xero sets contact_number = contact_id (accounts_contact_id) if not set by CiviCRM.
      // We can only use it if it is an integer (map it to CiviCRM contact_id).
      $contactID = CRM_Utils_Type::validate($xeroContact['contact_number'] ?? NULL, 'Integer', FALSE);
      if ($contactID) {
        $accountContactParams['contact_id'] = $contactID;
        $or[] = ['contact_id', '=', $contactID];
      }

      $save = TRUE;
      CRM_Accountsync_Hook::accountPullPreSave('contact', $xeroContact, $save, $accountContactParams);
      if (!$save) {
        continue;
      }

      $accountsContactID = $xeroContact['contact_id'];
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
          ->addValue('accounts_data', json_encode($xeroContact))
          ->execute();
        $errors[] = 'Duplicate records found for accounts_contact_id: ' . $accountsContactID . ', contact_id: ' . $contactID;
        // We recorded the error - now continue with syncing the rest
        continue;
      }

      // Check that the CiviCRM contact ID is valid.
      // If the CiviCRM contact ID does not exist but is set it was probably deleted in CiviCRM.
      if ($contactID) {
        $civicrmContact = Contact::get(FALSE)
          ->addWhere('id', '=', $contactID)
          ->execute()
          ->first();
        if (empty($civicrmContact)) {
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
        $errors[] = E::ts('Failed to store %1 (%2)', [1 => $xeroContact['name'], 2 => $xeroContact['contact_id']])
          . E::ts(' with error ') . $e->getMessage();
      }
    }
    if ($errors) {
      \Civi::log('xero')->warning('Not all records were saved {errors}', ['errors' => $errors]);
      // Since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(E::ts('Not all records were saved') . ': ' . print_r($errors, TRUE), 'incomplete', $errors);
    }
    if (!empty($ids)) {
      \Civi::log('xero')->info('Xero Contact Pull: {count} IDs retrieved {ids}', ['count' => count($ids), 'ids' => implode(', ', $ids)]);
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
        // Address is different to the other location fields because it has multiple fields.
        // We might return NULL from getPreferredAddress which means "do not sync to Xero".
        // That way we preserve any partial address that we might have in Xero and it will be synced next time it's pulled to Civi.
        $contactAddress = $this->getPreferredAddress($locationTypeToSync, $record['contact_id']);
        if ($contactAddress) {
          $contact = array_merge($contact, $contactAddress);
        }

        $xeroContactUUID = !empty($record['accounts_contact_id']) ? $record['accounts_contact_id'] : NULL;
        $accountsContact = $this->mapToAccounts($contact, $xeroContactUUID);
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
              ->first()['is_deleted'] ?? FALSE;
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
      catch (\Exception $e) {
        // Note: Using \Exception here as we may get various exception types from the Xero API/SDK
        $errorMessage = E::ts('Failed to push contactID: %1') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
          . E::ts('Error: ') . $e->getMessage() . '; '
          . E::ts('Record: ') . print_r($record,TRUE) . '; '
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
      foreach ($this->getAddressFieldMap() as $key => $api4Key) {
        $addressResult[$key] = $address[$api4Key];
      }
      return $addressResult;
    }
    return NULL;
  }

  private function getAddressFieldMap(): array {
    return [
      'street_address' => 'street_address',
      'city' => 'city',
      'postal_code' => 'postal_code',
      'supplemental_address_1' => 'supplemental_address_1',
      'supplemental_address_2' => 'supplemental_address_2',
      'supplemental_address_3' => 'supplemental_address_3',
      'country' => 'country_id:label',
      'state_province_name' => 'state_province_id:label',
    ];
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
      ->addWhere('connector_id', '=', $params['connector_id'])
      ->addWhere('do_not_sync', '<>', TRUE)
      ->setLimit($limit);

    // If we specified a CiviCRM contact ID just push that contact.
    if (!empty($params['contact_id'])) {
      $accountContacts->addWhere('contact_id', '=', $params['contact_id']);
    }
    else {
      $accountContacts->addWhere('accounts_needs_update', '=', TRUE);
      $accountContacts->addWhere('contact_id', 'IS NOT NULL');
      // Only select AccountContacts for push if error is resolved or there is no error.
      $accountContacts->addClause('OR', ['is_error_resolved', '=', TRUE], ['error_data', 'IS EMPTY']);
    }
    $accountContacts->addOrderBy('accounts_contact_id');
    $accountContacts->addOrderBy('error_data');

    return (array) $accountContacts->execute();
  }

  /**
   * Map civicrm Array to Accounts package field names.
   *
   * @param array $contact
   *          Contact Array as returned from API
   * @param string|null $xeroContactUUID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  protected function mapToAccounts(array $contact, ?string $xeroContactUUID) {
    $new_contact = [
      'Name' => $contact['display_name'] . ' - ' . $contact['id'],
      'FirstName' => $contact['first_name'] ?? '',
      'LastName' => $contact['last_name'] ?? '',
      'EmailAddress' => CRM_Utils_Rule::email($contact['email']) ? $contact['email'] : '',
      'ContactNumber' => $contact['id'],
    ];

    // Only map Phone if we have one
    if (isset($contact['phone'])) {
      $new_contact['Phones'] = [
        'Phone' => [
          'PhoneType' => 'DEFAULT',
          'PhoneNumber' => $contact['phone'],
        ],
      ];
    }
    // Only map address if an address was found
    foreach ($this->getAddressFieldMap() as $key => $api4Key) {
      if (isset($contact[$key])) {
        // We have an address (at least a partial one)
        $new_contact['Addresses'] = [
          'Address' => [
            [
              'AddressType' => 'POBOX', // described in documentation as the default mailing address for invoices http://blog.xero.com/developer/api/types/#Addresses
              'AddressLine1' => $contact['street_address'] ?? '',
              'City' => $contact['city'] ?? '',
              'PostalCode' => $contact['postal_code'] ?? '',
              'AddressLine2' => $contact['supplemental_address_1'] ?? '',
              'AddressLine3' => $contact['supplemental_address_2'] ?? '',
              'AddressLine4' => $contact['supplemental_address_3'] ?? '',
              'Country' => $contact['country'] ?? '',
              'Region' => $contact['state_province_name'] ?? '',
            ],
          ],
        ];
        break;
      }
    }

    if (!empty($xeroContactUUID)) {
      $new_contact['ContactID'] = $xeroContactUUID;
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
