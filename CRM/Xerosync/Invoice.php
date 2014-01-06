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
          // this is an update

        }
        try {
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
      'api.contrtibution.get' => 1,
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
   * Pre process an invoice - this was part of the old CiviXero - for now we will just store it & re-use later
   * in a prePushHook
   */
  function validatePrerequisites($invoice){
    static $trackingOptions = array();
    if(empty($trackingOptions)){
      $tc = $xero->TrackingCategories;
      foreach($tc['TrackingCategories']['TrackingCategory'] as $trackingCategory){
        foreach ($trackingCategory['Options']['Option'] as $key =>$value){
          $trackingOptions[] = $value['Name'];
        }
      }
    }
    if(!in_array($invoice['block'],$trackingOptions)){
      $errors[] = "Tracking Category " . $invoice['block'] ." needs to be created in Xero before the invoice syncronisation can be completed";
    }
    if(!in_array($invoice['event_code'],$trackingOptions)){
      $errors[] = "Tracking Category " . $invoice['event_code'] ." needs to be created in Xero before the invoice syncronisation can be completed";
    }

    return $errors;
  }
}