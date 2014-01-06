<?php

class CRM_Xerosync_Contact extends CRM_Xerosync_Base {

  /**
   * pull contacts from Xero and store them into civicrm_account_contact
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   * @throws API_Exception
   */
  function pull($params) {
    $result = $this->getSingleton()->Contacts(false, $this->formatDateForXero($params['start_date']));
    if(!is_array($result)){
      throw new API_Exception('Sync Failed', 'xero_retrieve_failure', $result);
    }
    if (!empty($result['Contacts'])){
      CRM_Core_Session::setStatus(count($result['Contacts']) . ts(' retrieved'), ts('Contact Pull'));
      foreach($result['Contacts']['Contact'] as $contact){
        $save = TRUE;
        $params = array(
          'accounts_display_name' => $contact['Name'],
          'contact_id' => CRM_Utils_Array::value('ContactNumber', $contact),
          'accounts_modified_date' => $contact['UpdatedDateUTC'],
          'plugin' => 'xero',
          'accounts_contact_id' => $contact['ContactID'],
          'accounts_data' => json_encode($contact),
        );
        CRM_Accountsync_Hook::accountPullPreSave('contact', $contact, $save, $params);
        if(!$save) {
          continue;
        }
        try {
          $params['id'] = civicrm_api3('account_contact', 'getvalue', array(
            'return' => 'id',
            'accounts_contact_id' => $contact['ContactID'],
            'plugin' => $this->_plugin,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          // this is an update - but lets just check the contact id doesn't exist in the account_contact table first
          // e.g if a list has been generated but not yet pushed
          try {
            $existing = civicrm_api3('account_contact', 'getsingle', array(
              'return' => 'id',
              'contact_id' => $contact['ContactNumber'],
              'plugin' => $this->_plugin,
            ));
            if(!empty($existing['accounts_contact_id']) && $existing['accounts_contact_id'] != $contact['ContactID']) {
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
          . ts(' with error ') . $e->getMessage()
          , ts('Contact Pull failed'));
        }
      }
    }
  }

  /**
   * push contacts to Xero from the civicrm_account_contact with 'needs_update' = 1
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *  - start_date
   * @throws API_Exception
   */
  function push($params) {
    $records = civicrm_api3('account_contact', 'get', array(
      'accounts_needs_update' => 1,
      'api.contact.get' => 1,
      'plugin' => $this->_plugin,
      )
    );

    //@todo pass limit through from params to get call
    foreach ($records['values'] as $record) {
      try {
        $accountsContactID = $record['accounts_contact_id'];
        $civiCRMcontact  = $record['api.contact.get'];
        $accountsContact = $this->mapToAccounts($record['api.contact.get']['values'][0], $accountsContactID);
        $result = $this->getSingleton()->Contacts($accountsContact);
        // we will expect the caller to deal with errors
        //@todo - not sure we should throw on first
        // perhaps we should save error data & it needs to be removed before we try again for this contact
        // that would allow us to continue with others
        $errors = $this->validateResponse($result);
        $record['error_data'] = $errors ? json_encode($errors) : NULL;
        //this will update the last sync date
        $record['accounts_needs_update'] = 0;
        unset($record['last_sync_date']);
        civicrm_api3('account_contact', 'create', $record);
      }
      catch (Exception $e) {
        // what do we need here? - or should we remove try catch as api will catch?
      }
    }
  }

  /**
   * Map civicrm Array to Accounts package field names
   *
   * @param array $contact
   *          Contact Array as returned from API
   * @param
   *          string accountsID ID from Accounting system
   * @return $accountsContact Contact Object/ array as expected by accounts package
   */
  function mapToAccounts($contact, $accountsID) {
    $new_contact = array (
      "Name" => $contact['display_name'],
      "FirstName" => $contact['first_name'],
      "LastName" => $contact['last_name'],
      "EmailAddress" => CRM_Utils_Rule::email($contact['email']) ? $contact['email'] : '',
      "ContactNumber" => $contact['contact_id'],
      "Addresses" => array (
        "Address" => array (
          array (
            "AddressType" => 'POBOX', // described in documentation as the default mailing address for invoices http://blog.xero.com/developer/api/types/#Addresses
            "AddressLine1" => $contact['street_address'],
            "City" => $contact['city'],
            "PostalCode" => $contact['postal_code']
          )
        )
      ),
      "Phones" => array (
        "Phone" => array (
          "PhoneType" => 'DEFAULT',
          "PhoneNumber" => $contact['phone']
        )
      )
    );
    if (! empty($accountsID)) {
      $new_contact['ContactID'] = $accountsID;
    }
    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('contact', $contact, $proceed, $new_contact);
    $new_contact = array (
      $new_contact
    );
    if (! $proceed) {
      return FALSE;
    }
    return $new_contact;
  }
}