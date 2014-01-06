<?php

class CRM_Xerosync_Invoice extends CRM_Xerosync_Base {
  protected $_plugin = 'xero';
  /**
   * pull contacts from Xero and store them into civicrm_account_contact
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   * @throws API_Exception
   */
  function pull($params) {
    $result = $this->getSingleton()->Invoices(false, $this->formatDateForXero($params['start_date']), array ("Type" => "ACCREC" ));
    if(!is_array($result)){
      throw new API_Exception('Sync Failed', 'xero_retrieve_failure', $result);
    }
    if (!empty($result['Invoices'])){
      CRM_Core_Session::setStatus(count($result['Invoices']) . ts(' retrieved'), ts('Invoice Pull'));
      foreach($result['Invoices']['Invoice'] as $invoice){
        $save = TRUE;
        $params = array(
          'contribution_id' => CRM_Utils_Array::value('InvoiceNumber', $invoice),
          'accounts_modified_date' => $invoice['UpdatedDateUTC'],
          'plugin' => 'xero',
          'accounts_invoice_id' => $invoice['InvoiceID'],
          'accounts_data' => json_encode($invoice),
          'accounts_status_id' => $this->mapStatus($invoice['Status']),
          'accounts_needs_update' => 0,
        );
        CRM_Accountsync_Hook::accountPullPreSave('invoice', $invoice, $save, $params);
        if(!$save) {
          continue;
        }
        try {
          $params['id'] = civicrm_api3('account_invoice', 'getvalue', array(
            'return' => 'id',
            'accounts_invoice_id' => $invoice['InvoiceID'],
            'plugin' => $this->_plugin,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          // this is an update - but lets just check the contact id doesn't exist in the account_contact table first
          // e.g if a list has been generated but not yet pushed
          try {
            $existing = civicrm_api3('account_invoice', 'getsingle', array(
              'return' => 'id',
              'contribution_id' => $invoice['InvoiceNumber'],
              'plugin' => $this->_plugin,
            ));
            $params['id'] = $existing['id'];
            if(!empty($existing['accounts_invoice_id']) && $existing['accounts_invoice_id'] != $invoice['InvoiceID']) {
              // no idea how this happened or what it means - calling function can catch & deal with it
              throw new CRM_Core_Exception(ts('Cannot update invoice'), 'data_error', $invoice);
            }
          }
          catch (CiviCRM_API3_Exception $e) {
            // ok - it IS an update
          }
        }
        try {
          //@todo - remove try catch here - this layer should throw exceptions
          $result = civicrm_api3('account_invoice', 'create', $params);
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Session::setStatus(ts('Failed to store ') . $invoice['InvoiceID']
          . ts(' with error ') . $e->getMessage()
          , ts('Invoice Pull failed'));
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
    $records = civicrm_api3('account_invoice', 'get', array(
      'accounts_needs_update' => 1,
      'api.account_invoice.getderived' => array('id' => '$value.contribution_id'),
      'plugin' => $this->_plugin,
      )
    );
    //@todo pass limit through from params to get call
    foreach ($records['values'] as $record) {
      try {
        $accountsContactID = $record['accounts_contact_id'];
        $civiCRMcontact  = $record['api.contact.get'];
        $accountsContact = $this->mapToAccounts($record['api.contact.get']['values'][0], $accountsContactID);
        $result = $this->getSingleton()->Invoices($accountsContact);
        // we will expect the caller to deal with errors
        //@todo - not sure we should throw on first
        // perhaps we should save error data & it needs to be removed before we try again for this contact
        // that would allow us to continue with others
        $errors = $this->validateResponse($result);
        $record['error_data'] = $errors ? json_encode($errors) : NULL;
        //this will update the last sync date & anything hook-modified
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
   * @param array $invoiceData  - require
   *  contribution fields
   *   - line items
   *   - receive date
   *   - source
   *   - contact_id
   * @param string accountsID ID from Accounting system
   * @return $accountsContact Contact Object/ array as expected by accounts package
   */
  function mapToAccounts($invoiceData, $accountsID) {
    $lineItems = array();
    foreach ($invoiceData['line_items'] as $lineitem) {
      $lineItems[] = array(
        "Description" => $lineitem['display_name'] . $lineitem['label'],
        "Quantity"    => $lineitem['qty'],
        "UnitAmount"  => $lineitem['unit_price'],
        "AccountCode" => $lineitem['accounting_code'],
      );
    }

    $new_invoice = array(
      "Type" => "ACCREC",
      "Contact" => array(
        "ContactNumber" => $invoiceData['contact_id'],
      ),
      "Date"            => substr($invoiceData['receive_date'], 0, 10),
      "DueDate"         => substr($invoiceData['receive_date'], 0, 10),
      "Status"          => "SUBMITTED",
      "InvoiceNumber"   => $invoiceData['id'],
      "CurrencyCode"    => CRM_Core_Config::singleton()->defaultCurrency,
      "Reference"       => $invoiceData['display_name'] . ' ' . $invoiceData['contribution_source'],
      "LineAmountTypes" => "Inclusive",
      'LineItems' => array('LineItem' => $lineItems),
    );

    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('invoice', $invoiceData, $proceed, $new_invoice);
    if (!$proceed) {
      return FALSE;
    }
    $this->validatePrerequisites($new_invoice);
    $new_invoice = array (
      $new_invoice
    );
    return $new_invoice;
  }

  /**
   * Map Xero Status values against CiviCRM status values
   *
   */
  function mapStatus($status) {
    $statuses = array(
      'PAID' => 1,
      'DELETED' => 3,
      'VOIDED' => 3,
      'DRAFT' => 2,
      'AUTHORISED' => 2,
      'SUBMITTED' => 2,
    );
    return $statuses[$status];
  }

  /**
   * Validate an invoice by checking the tracking category exists (if set)
   * @param array $invoice array ready for Xero
   */
  function validatePrerequisites($invoice){
    static $trackingOptions = array();
    if(empty($trackingOptions)){
      $trackingOptions = civicrm_api3('xerosync', 'trackingcategorypull', array());
      $trackingOptions = $trackingOptions['values'];
    }
    if(empty($invoice['LineItems'])) {
      return;
    }
    foreach ($invoice['LineItems']['LineItem'] as $lineItems) {
      foreach ($lineItems as $lineItem) {
        if(empty($lineItem['TrackingCategory'])) {
          continue;
        }
        foreach ($lineItem['TrackingCategory'] as $tracking) {
          if(!array_key_exists($tracking['Name'], $trackingOptions)
            || !in_array($tracking['Option'], $trackingOptions[$tracking['Name']])) {
            throw new CRM_Core_Exception(ts('Tracking Category Does Not Exist ') . $tracking['Name'] . ' ' . $tracking['Option'],'invalid_tracking', $tracking);
          }
        }
      }
    }
  }
}