<?php

use CRM_Civixero_ExtensionUtil as E;
use Civi\Api4\AccountContact;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Api4\LocationType;
use XeroAPI\XeroPHP\AccountingObjectSerializer;

class CRM_Civixero_Contact extends CRM_Civixero_Base {

  /**
   * Cached Xero contact group ID for the configured group name.
   * FALSE means we already looked it up and it wasn't found.
   *
   * @var string|false|null
   */
  private static $cachedContactGroupId = NULL;

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
    //$iDs = ["00000000-0000-0000-0000-000000000000"];
    $ids = NULL;
    $contact = [];

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
              // Nested SDK models (phones, addresses, contact_persons, ...)
              // do not implement JsonSerializable, so a raw json_encode()
              // in processPull() would flatten them to {} and the phone
              // duplicate-guard index would see no phone data. Sanitize into
              // plain values with the SDK's own serializer (CamelCase keys,
              // matching what getXeroPhoneIndex() expects).
              $contact[$localName] = AccountingObjectSerializer::sanitizeForSerialization($xeroContact->$getter());
          }
        }
        $contacts[$contact['contact_id']] = $contact;
      }
    } catch (\InvalidArgumentException $e) {
      // This means there are no contacts returned for the requested page. That's ok!
      return [];
    } catch (\Exception $e) {
      \Civi::log(E::SHORT_NAME)->error('Exception when calling AccountingApi->getContacts: ' . $e->getMessage());
      throw $e;
    }
    return $contacts ?? [];
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
      \Civi::log(E::SHORT_NAME)->error('CiviXero: Error when running Contact Pull: ' . $e->getMessage());
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
        if (!empty($accountContacts->first()['do_not_sync'])) {
          // Row carries the durable-unmatch lock: refresh the Xero-side
          // data but never (re)derive the CiviCRM link for it.
          unset($accountContactParams['contact_id']);
        }
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
          // The ContactNumber-derived CiviCRM link is part of what pull
          // maintains: if it differs from the stored link (e.g. re-match
          // after the durable-unmatch lock was cleared), that alone must
          // trigger the update - the Xero-side fields may be unchanged.
          if (array_key_exists('contact_id', $accountContactParams)
            && (int) $accountContactParams['contact_id'] !== (int) ($accountContacts->first()['contact_id'] ?? 0)) {
            $modifiedFieldKeys[] = 'contact_id';
            $accountContactParams['contact_id'] = (int) $accountContactParams['contact_id'];
          }
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
      \Civi::log(E::SHORT_NAME)->warning('Not all records were saved {errors}', ['errors' => $errors]);
      // Since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(E::ts('Not all records were saved') . ': ' . print_r($errors, TRUE), 'incomplete', $errors);
    }
    if (!empty($ids)) {
      \Civi::log(E::SHORT_NAME)->info('Xero Contact Pull: {count} IDs retrieved {ids}', ['count' => count($ids), 'ids' => implode(', ', $ids)]);
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
          // A hook listener vetoed the push for this contact: skip it cleanly.
          // (Upstream fell through here and read array keys off boolean FALSE.)
          continue;
        }

        // Duplicate guard: when about to CREATE (no ContactID yet), check
        // whether Xero already holds this contact - first by ContactNumber
        // (which this extension sets to the CiviCRM contact ID), then by
        // exact email match. If found, update that record instead of
        // creating a duplicate. Never fuzzy-matches on name.
        if (empty($accountsContact['ContactID']) && empty($params['skip_duplicate_guard'])) {
          // skip_duplicate_guard is only ever set in-memory by
          // Matcher::forceCreateInXero() after explicit human confirmation.
          $existingUUID = $this->findExistingXeroContact($contact);
          if ($existingUUID !== NULL) {
            $accountsContact['ContactID'] = $existingUUID;
            \Civi::log(E::SHORT_NAME)->info('Contact Push linked CiviCRM contact ' . $record['contact_id'] . ' to existing Xero contact ' . $existingUUID . ' instead of creating a duplicate');
          }
        }

        // Pre-push conflict check: if the target Xero contact is already
        // linked to a DIFFERENT (non-deleted) CiviCRM contact, fail BEFORE
        // writing to Xero. Live testing showed the post-push check further
        // down only rejected the link AFTER the push had already renamed
        // the other contact's Xero record.
        if (!empty($accountsContact['ContactID'])) {
          $preMatch = AccountContact::get(FALSE)
            ->addWhere('accounts_contact_id', '=', $accountsContact['ContactID'])
            ->addWhere('plugin', '=', $this->_plugin)
            ->addWhere('connector_id', '=', $params['connector_id'])
            ->addWhere('contact_id', 'IS NOT NULL')
            ->addWhere('contact_id', '<>', $record['contact_id'])
            ->execute()
            ->first();
          if (!empty($preMatch)) {
            $preMatchContactIsDeleted = Contact::get(FALSE)
              ->addWhere('id', '=', $preMatch['contact_id'])
              ->addWhere('is_deleted', '=', TRUE)
              ->execute()
              ->first()['is_deleted'] ?? FALSE;
            if (!$preMatchContactIsDeleted) {
              // Same wording as the post-push check so both land in the
              // worklist identically.
              throw new CRM_Core_Exception(ts('Attempt to sync Contact %1 to Xero entry for existing Contact %2. ', [
                1 => $record['contact_id'],
                2 => $preMatch['contact_id'],
              ]), 'xero_dup_contact');
            }
            // Linked contact is deleted: allow the push; the post-push
            // logic below repairs the stale row.
          }
        }

        $result = $this->pushContactToXero($accountsContact);

        /* When Xero returns an ID that matches an existing account_contact, update it instead. */
        $matchingAccountContact = AccountContact::get(FALSE)
          ->addWhere('accounts_contact_id', '=', $result['ContactID'])
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
          $record['accounts_contact_id'] = $result['ContactID'];
        }
        $record['accounts_modified_date'] = $result['UpdatedDateUTC'];
        $record['accounts_data'] = $result['accounts_data'];
        $record['accounts_display_name'] = $result['Name'];
        // This will update the last sync date.
        unset($record['last_sync_date']);
        AccountContact::update(FALSE)
          ->setValues($record)
          ->addValue('accounts_needs_update', FALSE)
          ->execute();
        $this->addContactToXeroGroup($record['accounts_contact_id']);
      }
      catch (CRM_Civixero_Exception_XeroThrottle $e) {
        $errors[] = E::ts('Contact Push aborted due to throttling by Xero');
        CRM_Civixero_Base::setApiRateLimitExceeded($e->getRetryAfter());
        break;
      }
      catch (\Exception $e) {
        // Note: Using \Exception here as we may get various exception types from the Xero API/SDK
        $errorMessage = E::ts('Failed to push contactID: %1') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
          . E::ts('Error: ') . $e->getMessage() . '; '
          . E::ts('Record: ') . print_r($record,TRUE) . '; '
          . E::ts('Contact Push failed');

        // Deliberately do NOT overwrite accounts_data here - upstream stored
        // the CiviCRM contact array into it on failure, destroying the
        // last-known Xero snapshot for this record.
        AccountContact::update(FALSE)
          ->addWhere('id', '=', $record['id'])
          ->addValue('is_error_resolved', FALSE)
          ->addValue('error_data', json_encode([
            'error' => $e->getMessage(),
            'error_data' => $record['error_data']
          ]))
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
   * Add a contact to the configured Xero Contact Group after a successful push.
   *
   * Does nothing if no group name is configured in settings.
   * Logs a warning if the group cannot be found; does not throw so as not to
   * disrupt the contact push itself.
   *
   * @param string $xeroContactId
   */
  private function addContactToXeroGroup(string $xeroContactId): void {
    $groupName = Civi::settings()->get('xero_contact_group');
    if (empty($groupName)) {
      return;
    }
    $groupId = $this->getXeroContactGroupId($groupName);
    if ($groupId === FALSE) {
      return;
    }
    try {
      $contact = new \XeroAPI\XeroPHP\Models\Accounting\Contact();
      $contact->setContactId($xeroContactId);
      $contacts = new \XeroAPI\XeroPHP\Models\Accounting\Contacts();
      $contacts->setContacts([$contact]);
      $this->getAccountingApiInstance()->createContactGroupContacts(
        $this->getTenantID(),
        $groupId,
        $contacts
      );
      \Civi::log(E::SHORT_NAME)->info(sprintf('CiviXero: Successfully added contact %s to Xero group "%s"', $xeroContactId, $groupName));
    }
    catch (\Exception $e) {
      \Civi::log(E::SHORT_NAME)->warning(sprintf('CiviXero: Failed to add contact %s to Xero group "%s": %s', $xeroContactId, $groupName, $e->getMessage()));
    }
  }

  /**
   * Get the Xero Contact Group ID for the given group name, with static caching.
   *
   * Returns FALSE if the group was not found, so we do not repeat the lookup.
   *
   * @param string $groupName
   *
   * @return string|false
   */
  private function getXeroContactGroupId(string $groupName) {
    if (self::$cachedContactGroupId !== NULL) {
      return self::$cachedContactGroupId;
    }
    try {
      $contactGroups = $this->getAccountingApiInstance()
        ->getContactGroups($this->getTenantID(), 'Name=="' . addslashes($groupName) . '"');
      foreach ($contactGroups->getContactGroups() as $group) {
        if ($group->getName() === $groupName) {
          self::$cachedContactGroupId = $group->getContactGroupId();
          return self::$cachedContactGroupId;
        }
      }
    }
    catch (\Exception $e) {
      \Civi::log(E::SHORT_NAME)->warning(sprintf('CiviXero: Failed to look up Xero Contact Group "%s": %s', $groupName, $e->getMessage()));
    }
    // Group not found or lookup failed — cache FALSE to avoid repeated API calls.
    self::$cachedContactGroupId = FALSE;
    return FALSE;
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
    // Xero limits Name/FirstName/LastName/EmailAddress to 255 characters.
    // The ' - <id>' suffix keeps Xero names unique, so truncate the
    // display-name part, never the suffix.
    $nameSuffix = ' - ' . $contact['id'];
    $email = (string) ($contact['email'] ?? '');
    if ($email !== '' && !CRM_Utils_Rule::email($email)) {
      \Civi::log(E::SHORT_NAME)->warning('Contact ' . $contact['id'] . ' has an invalid email - pushing without an email address');
      $email = '';
    }
    $new_contact = [
      'Name' => \CRM_Utils_String::ellipsify((string) $contact['display_name'], 255, $nameSuffix),
      'FirstName' => \CRM_Utils_String::ellipsify($contact['first_name'] ?? '',255),
      'LastName' => \CRM_Utils_String::ellipsify($contact['last_name'] ?? '', 255),
      'EmailAddress' => $email,
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
   * Push a single mapped contact to Xero via the official SDK.
   *
   * Replaces the legacy hand-rolled client (packages/Xero/Xero.php) which
   * disabled SSL peer verification on every request.
   *
   * @param array $mappedContact
   *   CamelCase-keyed contact array as produced by mapToAccounts().
   *
   * @return array
   *   ContactID, UpdatedDateUTC ('Y-m-d H:i:s'), Name, accounts_data (JSON snapshot).
   *
   * @throws \CRM_Core_Exception
   */
  private function pushContactToXero(array $mappedContact): array {
    $xeroContact = $this->mappedArrayToXeroContact($mappedContact);

    // Validate locally before spending an API call; fails with a clearer
    // message than the remote 400 would give.
    $invalidProperties = $xeroContact->listInvalidProperties();
    if ($invalidProperties !== []) {
      throw new CRM_Core_Exception('contact failed local validation: ' . implode('; ', $invalidProperties));
    }

    $contacts = new \XeroAPI\XeroPHP\Models\Accounting\Contacts();
    $contacts->setContacts([$xeroContact]);

    try {
      // summarize_errors = FALSE: per-contact validation errors come back on
      // the contact object instead of a blanket HTTP 400.
      $response = $this->getAccountingApiInstance()->updateOrCreateContacts(
        $this->getTenantID(),
        $contacts,
        FALSE,
        $this->generateIdempotencyKey('contact-' . ($mappedContact['ContactNumber'] ?? '0'), $mappedContact)
      );
    }
    catch (\XeroAPI\XeroPHP\ApiException $e) {
      $this->handleApiException($e, 'updateOrCreateContacts');
    }

    $returned = $response->getContacts()[0] ?? NULL;
    if ($returned === NULL) {
      throw new CRM_Core_Exception('Xero returned no contact from updateOrCreateContacts');
    }
    $validationErrors = $returned->getValidationErrors() ?? [];
    if ($validationErrors !== []) {
      $messages = [];
      foreach ($validationErrors as $validationError) {
        $messages[] = $validationError->getMessage();
      }
      throw new CRM_Core_Exception('Xero rejected the contact: ' . implode('; ', $messages));
    }

    $updated = $returned->getUpdatedDateUtcAsDate();
    return [
      'ContactID' => $returned->getContactId(),
      'UpdatedDateUTC' => $updated ? $updated->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
      'Name' => $returned->getName(),
      'accounts_data' => (string) $returned,
    ];
  }

  /**
   * Convert a CamelCase mapped-contact array to an SDK Contact model.
   *
   * Field lengths are clamped to Xero's documented limits so a single bad
   * value cannot fail the whole push batch.
   */
  private function mappedArrayToXeroContact(array $mappedContact): \XeroAPI\XeroPHP\Models\Accounting\Contact {
    $xeroContact = new \XeroAPI\XeroPHP\Models\Accounting\Contact();
    if (!empty($mappedContact['ContactID'])) {
      $this->assertValidXeroGuid((string) $mappedContact['ContactID'], 'Xero contact reference (ContactID)');
      $xeroContact->setContactId($mappedContact['ContactID']);
    }
    $xeroContact->setName($mappedContact['Name']);
    $xeroContact->setContactNumber((string) $mappedContact['ContactNumber']);
    if (($mappedContact['FirstName'] ?? '') !== '') {
      $xeroContact->setFirstName($mappedContact['FirstName']);
    }
    if (($mappedContact['LastName'] ?? '') !== '') {
      $xeroContact->setLastName($mappedContact['LastName']);
    }
    if (($mappedContact['EmailAddress'] ?? '') !== '') {
      $xeroContact->setEmailAddress($mappedContact['EmailAddress']);
    }

    if (!empty($mappedContact['Phones']['Phone']['PhoneNumber'])) {
      $phone = new \XeroAPI\XeroPHP\Models\Accounting\Phone();
      $phone->setPhoneType(\XeroAPI\XeroPHP\Models\Accounting\Phone::PHONE_TYPE__DEFAULT);
      $phone->setPhoneNumber(mb_substr((string) $mappedContact['Phones']['Phone']['PhoneNumber'], 0, 50));
      $xeroContact->setPhones([$phone]);
    }

    if (!empty($mappedContact['Addresses']['Address'][0])) {
      $mappedAddress = $mappedContact['Addresses']['Address'][0];
      $address = new \XeroAPI\XeroPHP\Models\Accounting\Address();
      $address->setAddressType(\XeroAPI\XeroPHP\Models\Accounting\Address::ADDRESS_TYPE_POBOX);
      $address->setAddressLine1(mb_substr((string) ($mappedAddress['AddressLine1'] ?? ''), 0, 500));
      $address->setAddressLine2(mb_substr((string) ($mappedAddress['AddressLine2'] ?? ''), 0, 500));
      $address->setAddressLine3(mb_substr((string) ($mappedAddress['AddressLine3'] ?? ''), 0, 500));
      $address->setAddressLine4(mb_substr((string) ($mappedAddress['AddressLine4'] ?? ''), 0, 500));
      $address->setCity(mb_substr((string) ($mappedAddress['City'] ?? ''), 0, 255));
      $address->setPostalCode(mb_substr((string) ($mappedAddress['PostalCode'] ?? ''), 0, 50));
      $address->setCountry(mb_substr((string) ($mappedAddress['Country'] ?? ''), 0, 50));
      $address->setRegion(mb_substr((string) ($mappedAddress['Region'] ?? ''), 0, 255));
      $xeroContact->setAddresses([$address]);
    }
    return $xeroContact;
  }

  /**
   * Look for an existing Xero contact before creating a new one.
   *
   * Match order: ContactNumber (= CiviCRM contact ID, set by this extension),
   * then exact email address. Deliberately never fuzzy-matches on name.
   *
   * @return string|null
   *   The Xero ContactID to link to, or NULL when Xero has no match.
   *
   * @throws \CRM_Core_Exception
   *   When the match is ambiguous - manual matching is required rather than
   *   guessing (the error lands in error_data for the worklist).
   */
  private function findExistingXeroContact(array $contact): ?string {
    $byNumber = $this->getXeroContactsWhere('ContactNumber=="' . (int) $contact['id'] . '"');
    if (count($byNumber) === 1) {
      return $byNumber[0]->getContactId();
    }
    if (count($byNumber) > 1) {
      throw new CRM_Core_Exception('multiple Xero contacts carry ContactNumber ' . (int) $contact['id'] . ' - manual match required before this contact can be pushed.');
    }

    $email = (string) ($contact['email'] ?? '');
    if ($email !== '' && CRM_Utils_Rule::email($email)) {
      // Strip characters that could break out of the Xero where-clause
      // string literal; a valid email cannot contain them anyway.
      $escapedEmail = str_replace(['\\', '"'], '', $email);
      $byEmail = $this->getXeroContactsWhere('EmailAddress=="' . $escapedEmail . '"');
      if (count($byEmail) === 1) {
        return $byEmail[0]->getContactId();
      }
      if (count($byEmail) > 1) {
        throw new CRM_Core_Exception('multiple Xero contacts share the email of CiviCRM contact ' . (int) $contact['id'] . ' - manual match required before this contact can be pushed.');
      }
      // Contact HAS a usable email: email is the authoritative key and the
      // search is complete. Phone matching is only for email-less contacts.
      return NULL;
    }

    // No usable email: fall back to phone matching.
    return $this->findExistingXeroContactByPhone($contact);
  }

  /**
   * Phone-based duplicate guard for contacts with no usable email.
   *
   * Policy (approved 2026-07-03): individuals only; mobile numbers first
   * (Main-location/primary preferred), then landline-pattern numbers but
   * ONLY when no other CiviCRM contact holds the same number (landlines are
   * shared by households); a single Xero hit auto-links only when the
   * surname also corroborates. Ambiguous hits, hits already linked to a
   * different contact, and surname mismatches all throw to the error
   * worklist rather than guessing.
   *
   * Xero's API cannot search by phone, so matching runs against the local
   * snapshots in civicrm_account_contact.accounts_data (populated by
   * contact pull - a full pull is a rollout prerequisite).
   *
   * @return string|null
   *   Xero ContactID to link, or NULL to proceed with a create.
   *
   * @throws \CRM_Core_Exception
   */
  protected function findExistingXeroContactByPhone(array $contact): ?string {
    if (($contact['contact_type'] ?? '') !== 'Individual') {
      return NULL;
    }
    $candidates = $this->getMatchablePhoneCandidates((int) $contact['id']);
    if ($candidates === []) {
      return NULL;
    }
    $index = $this->getXeroPhoneIndex((int) $this->connector_id);
    foreach ($candidates as $candidate) {
      if (!$candidate['isMobile'] && !$this->isPhoneUniqueInCiviCRM($candidate['key'])) {
        // Landline shared by more than one CiviCRM contact: not a safe key.
        continue;
      }
      $hits = array_values($index[$candidate['key']] ?? []);
      if ($hits === []) {
        continue;
      }
      if (count($hits) > 1) {
        throw new CRM_Core_Exception('multiple Xero contacts share the phone number ending ' . substr($candidate['key'], -4) . ' held by CiviCRM contact ' . (int) $contact['id'] . ' - manual match required before this contact can be pushed.');
      }
      $hit = $hits[0];
      if (!empty($hit['linked_contact_id']) && (int) $hit['linked_contact_id'] !== (int) $contact['id']) {
        throw new CRM_Core_Exception('phone match for CiviCRM contact ' . (int) $contact['id'] . ' is Xero contact ' . $hit['uuid'] . ', which is already linked to CiviCRM contact ' . (int) $hit['linked_contact_id'] . ' - manual match required.');
      }
      $civiLastName = mb_strtolower(trim((string) ($contact['last_name'] ?? '')));
      $xeroLastName = mb_strtolower(trim($hit['last_name']));
      if ($civiLastName === '' || $xeroLastName === '' || $civiLastName !== $xeroLastName) {
        throw new CRM_Core_Exception('CiviCRM contact ' . (int) $contact['id'] . ' phone-matches Xero contact ' . $hit['uuid'] . ' (' . $hit['name'] . ') but the surname does not corroborate - manual match required before this contact can be pushed.');
      }
      \Civi::log(E::SHORT_NAME)->info('Contact Push phone-matched CiviCRM contact ' . (int) $contact['id'] . ' to existing Xero contact ' . $hit['uuid'] . ' (surname corroborated)');
      return $hit['uuid'];
    }
    return NULL;
  }

  /**
   * Canonicalise an Australian phone number to its 9 significant digits.
   *
   * Handles both input masks ('99 9999 9999' landline, '9999 999 999'
   * mobile), +61/61 country prefixes, and Xero's split
   * country/area/number storage. NULL when not a plausible AU number.
   */
  protected function canonicalizeAustralianPhone(string $raw): ?string {
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '' || $digits === NULL) {
      return NULL;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '61')) {
      $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && $digits[0] === '0') {
      return substr($digits, 1);
    }
    if (strlen($digits) === 9 && in_array($digits[0], ['2', '3', '4', '7', '8'], TRUE)) {
      return $digits;
    }
    return NULL;
  }

  /**
   * Ordered, canonicalised phone candidates for a contact.
   *
   * Mobile-pattern numbers first (Main location, then primary, preferred),
   * then landline-pattern numbers in the same preference order. Pattern
   * (leading 4 = mobile) decides the bucket, not the stored phone type -
   * the shared-line risk follows the number, not the label.
   */
  protected function getMatchablePhoneCandidates(int $contactID): array {
    $phones = Phone::get(FALSE)
      ->addSelect('phone', 'is_primary', 'location_type_id:name')
      ->addWhere('contact_id', '=', $contactID)
      ->execute();
    $mobiles = $landlines = [];
    foreach ($phones as $phone) {
      $key = $this->canonicalizeAustralianPhone((string) ($phone['phone'] ?? ''));
      if ($key === NULL) {
        continue;
      }
      $rank = ((($phone['location_type_id:name'] ?? '') === 'Main') ? 0 : 2)
        + (empty($phone['is_primary']) ? 1 : 0);
      if ($key[0] === '4') {
        $mobiles[$key] = min($mobiles[$key] ?? 9, $rank);
      }
      else {
        $landlines[$key] = min($landlines[$key] ?? 9, $rank);
      }
    }
    asort($mobiles);
    asort($landlines);
    $candidates = [];
    foreach (array_keys($mobiles) as $key) {
      $candidates[] = ['key' => $key, 'isMobile' => TRUE];
    }
    foreach (array_keys($landlines) as $key) {
      $candidates[] = ['key' => $key, 'isMobile' => FALSE];
    }
    return $candidates;
  }

  /**
   * Is this canonical number held by exactly one (non-deleted) contact?
   *
   * $key is digits-only by construction (canonicalizeAustralianPhone) and
   * bound as a parameter regardless - defence in depth.
   */
  protected function isPhoneUniqueInCiviCRM(string $key): bool {
    $count = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(DISTINCT p.contact_id)
         FROM civicrm_phone p
         INNER JOIN civicrm_contact c ON c.id = p.contact_id AND c.is_deleted = 0
        WHERE REGEXP_REPLACE(p.phone, '[^0-9]', '') LIKE %1",
      [1 => ['%' . $key, 'String']]
    );
    return $count === 1;
  }

  /**
   * Canonical-phone => Xero-contact index built from local snapshots.
   *
   * Built once per request (static cache) from accounts_data JSON, which
   * carries phones either as 'Phones' (push snapshots) or 'phones' (pull
   * snapshots); entries use the SDK's CamelCase field names in both cases.
   */
  protected function getXeroPhoneIndex(int $connectorID): array {
    if (isset(\Civi::$statics['civixero_phone_index'][$connectorID])) {
      return \Civi::$statics['civixero_phone_index'][$connectorID];
    }
    $index = [];
    $offset = 0;
    do {
      $rows = AccountContact::get(FALSE)
        ->addSelect('contact_id', 'accounts_contact_id', 'accounts_data')
        ->addWhere('plugin', '=', $this->_plugin)
        ->addWhere('connector_id', '=', $connectorID)
        ->addWhere('accounts_contact_id', 'IS NOT NULL')
        ->setLimit(500)
        ->setOffset($offset)
        ->execute();
      foreach ($rows as $row) {
        $data = json_decode((string) ($row['accounts_data'] ?? ''), TRUE);
        if (!is_array($data)) {
          continue;
        }
        $phones = $data['Phones'] ?? $data['phones'] ?? [];
        foreach ((array) $phones as $phoneData) {
          if (!is_array($phoneData)) {
            continue;
          }
          $raw = trim((string) ($phoneData['PhoneCountryCode'] ?? '') . (string) ($phoneData['PhoneAreaCode'] ?? '') . (string) ($phoneData['PhoneNumber'] ?? ''));
          $key = $raw === '' ? NULL : $this->canonicalizeAustralianPhone($raw);
          if ($key === NULL) {
            continue;
          }
          $index[$key][(string) $row['accounts_contact_id']] = [
            'uuid' => $row['accounts_contact_id'],
            'linked_contact_id' => $row['contact_id'],
            'last_name' => (string) ($data['LastName'] ?? $data['last_name'] ?? ''),
            'name' => (string) ($data['Name'] ?? $data['name'] ?? ''),
          ];
        }
      }
      $offset += 500;
    } while (count($rows) === 500);
    \Civi::$statics['civixero_phone_index'][$connectorID] = $index;
    return $index;
  }

  /**
   * Fetch (non-archived) Xero contacts matching a where clause.
   *
   * @return \XeroAPI\XeroPHP\Models\Accounting\Contact[]
   *
   * @throws \CRM_Core_Exception
   */
  private function getXeroContactsWhere(string $where): array {
    try {
      $response = $this->getAccountingApiInstance()->getContacts($this->getTenantID(), NULL, $where);
      return $response->getContacts() ?? [];
    }
    catch (\InvalidArgumentException $e) {
      // SDK quirk: thrown when the result set is empty.
      return [];
    }
    catch (\XeroAPI\XeroPHP\ApiException $e) {
      $this->handleApiException($e, 'getContacts');
    }
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
