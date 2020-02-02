<?php

class CRM_Civixero_Contact extends CRM_Civixero_Base {

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *
   * @throws API_Exception
   * @throws CRM_Core_Exception
   */
  public function pull($params) {
    // If we specify a xero contact id (UUID) then we try to load ONLY that contact.
    isset($params['xero_contact_id']) ?: $params['xero_contact_id'] = FALSE;
    try {
      $result = $this->getSingleton($params['connector_id'])
        ->Contacts($params['xero_contact_id'], $this->formatDateForXero($params['start_date']));
      if (!is_array($result)) {
        throw new API_Exception('Sync Failed', 'xero_retrieve_failure', (array) $result);
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
            'contact_id' => CRM_Utils_Array::value('ContactNumber', $contact),
            'accounts_modified_date' => $contact['UpdatedDateUTC'],
            'plugin' => 'xero',
            'accounts_contact_id' => $contact['ContactID'],
            'accounts_data' => json_encode($contact),
          ];
          CRM_Accountsync_Hook::accountPullPreSave('contact', $contact, $save, $params);
          if (!$save) {
            continue;
          }
          try {
            $params['id'] = civicrm_api3('account_contact', 'getvalue', [
              'return' => 'id',
              'accounts_contact_id' => $contact['ContactID'],
              'plugin' => $this->_plugin,
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
            // this is an update - but lets just check the contact id doesn't exist in the account_contact table first
            // e.g if a list has been generated but not yet pushed
            try {
              $existing = civicrm_api3('account_contact', 'getsingle', [
                'return' => 'id',
                'contact_id' => $contact['ContactNumber'],
                'plugin' => $this->_plugin,
              ]);
              if (!empty($existing['accounts_contact_id']) && $existing['accounts_contact_id'] != $contact['ContactID']) {
                // no idea how this happened or what it means - calling function can catch & deal with it
                throw new CRM_Core_Exception(ts('Cannot update contact'), 'data_error', $contact);
              }
            }
            catch (CiviCRM_API3_Exception $e) {
              // ok - it IS an update
            }
          }
          try {
            civicrm_api3('account_contact', 'create', $params);
          }
          catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Session::setStatus(ts('Failed to store ') . $params['accounts_display_name']
              . ts(' with error ') . $e->getMessage(),
              ts('Contact Pull failed'));
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
   * @throws CiviCRM_API3_Exception
   */
  public function push($params) {
    try {
      $records = civicrm_api3('account_contact', 'get', [
          'accounts_needs_update' => 1,
          'api.contact.get' => 1,
          'plugin' => $this->_plugin,
          'connector_id' => $params['connector_id'],
        ]
      );

      $errors = [];

      //@todo pass limit through from params to get call
      foreach ($records['values'] as $record) {
        try {
          $accountsContactID = !empty($record['accounts_contact_id']) ? $record['accounts_contact_id'] : NULL;
          $accountsContact = $this->mapToAccounts($record['api.contact.get']['values'][0], $accountsContactID);
          if ($accountsContact === FALSE) {
            $result = FALSE;
            $responseErrors = [];
          }
          else {
            $result = $this->getSingleton($params['connector_id'])->Contacts($accountsContact);
            $responseErrors = $this->validateResponse($result);
          }
          if ($result === FALSE) {
            unset($record['accounts_modified_date']);
          }
          elseif ($responseErrors) {
            $record['error_data'] = json_encode($responseErrors);
          }
          else {
            /* When Xero returns an ID that matches an existing account_contact, update it instead. */
            $matching = civicrm_api('account_contact', 'getsingle', [
                'accounts_contact_id' => $result['Contacts']['Contact']['ContactID'],
                'plugin' => $this->_plugin,
                'version' => 3,
              ]
            );
            if (!$matching['is_error']) {
              if (empty($matching['contact_id']) ||
                civicrm_api3('contact', 'getvalue', ['id' => $matching['contact_id'], 'return' => 'contact_is_deleted'])) {
                CRM_Core_Error::debug_log_message(ts('Updating existing contact for %1', [1 => $record['contact_id']]));
                civicrm_api3('account_contact', 'delete', ['id' => $record['id']]);
                $record['do_not_sync'] = 0;
                $record['id'] = $matching['id'];
              }
              elseif ($matching['contact_id'] != $record['contact_id']) {
                throw new CiviCRM_API3_Exception(ts('Attempt to sync Contact %1 to Xero entry for existing Contact %2. ', [
                  1 => $record['contact_id'],
                  2 => $matching['contact_id'],
                ], NULL, $record), 'xero_dup_contact');
              }
            }

            $record['error_data'] = 'null';
            if (empty($record['accounts_contact_id'])) {
              $record['accounts_contact_id'] = $result['Contacts']['Contact']['ContactID'];
            }
            $record['accounts_modified_date'] = $result['Contacts']['Contact']['UpdatedDateUTC'];
            $record['accounts_data'] = json_encode($result['Contacts']['Contact']);
            $record['accounts_display_name'] = $result['Contacts']['Contact']['Name'];
          }
          // This will update the last sync date.
          $record['accounts_needs_update'] = 0;
          unset($record['last_sync_date']);
          civicrm_api3('account_contact', 'create', $record);
        }
        catch (CiviCRM_API3_Exception $e) {
          $errors[] = ts('Failed to push ') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
            . ts(' with error ') . $e->getMessage() . print_r($responseErrors, TRUE)
            . ts('Contact Push failed');
        }
      }
      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all contacts were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return TRUE;
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      throw new CRM_Core_Exception('Contact Push aborted due to throttling by Xero');
    }
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
  protected function mapToAccounts($contact, $accountsID) {
    $new_contact = [
      "Name" => $contact['display_name'] . " - " . $contact['contact_id'],
      "FirstName" => $contact['first_name'],
      "LastName" => $contact['last_name'],
      "EmailAddress" => CRM_Utils_Rule::email($contact['email']) ? $contact['email'] : '',
      "ContactNumber" => $contact['contact_id'],
      "Addresses" => [
        "Address" => [
          [
            "AddressType" => 'POBOX', // described in documentation as the default mailing address for invoices http://blog.xero.com/developer/api/types/#Addresses
            "AddressLine1" => $contact['street_address'],
            "City" => $contact['city'],
            "PostalCode" => $contact['postal_code'],
            "AddressLine2" => CRM_Utils_Array::value('supplemental_address_1', $contact, ''),
            "AddressLine3" => CRM_Utils_Array::value('supplemental_address_2', $contact, ''),
            "AddressLine4" => CRM_Utils_Array::value('supplemental_address_3', $contact, ''),
            "Country" => CRM_Utils_Array::value('country', $contact, ''),
            "Region" => CRM_Utils_Array::value('state_province_name', $contact, ''),
          ],
        ],
      ],
      "Phones" => [
        "Phone" => [
          "PhoneType" => 'DEFAULT',
          "PhoneNumber" => $contact['phone'],
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
}
