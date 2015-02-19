<?php

class CRM_Civixero_Invoice extends CRM_Civixero_Base {
  protected $_plugin = 'xero';

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *
   * @return bool
   * @throws API_Exception
   * @throws CRM_Core_Exception
   */
  public function pull($params) {
    try {
      $result = $this->getSingleton()
        ->Invoices(FALSE, $this->formatDateForXero($params['start_date']), array("Type" => "ACCREC"));
      if (!is_array($result)) {
        throw new API_Exception('Sync Failed', 'xero_retrieve_failure', (array) $result);
      }
      $errors = array();
      if (!empty($result['Invoices'])) {
        $invoices = $result['Invoices']['Invoice'];
        if (isset($invoices['InvoiceID'])) {
          // the return syntax puts the contact only level higher up when only one contact is involved
          $invoices = array($invoices);
        }
        foreach ($invoices as $invoice) {
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
          if (!$save) {
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
              if (!empty($existing['accounts_invoice_id']) && $existing['accounts_invoice_id'] != $invoice['InvoiceID']) {
                // no idea how this happened or what it means - calling function can catch & deal with it
                throw new CRM_Core_Exception(ts('Cannot update invoice'), 'data_error', $invoice);
              }
            }
            catch (CiviCRM_API3_Exception $e) {
              // ok - it IS an update
            }
          }
          try {
            civicrm_api3('account_invoice', 'create', $params);
          }
          catch (CiviCRM_API3_Exception $e) {
            $errors[] = ts('Failed to store ') . $invoice['InvoiceNumber'] . ' (' . $invoice['InvoiceID'] . ' )'
              . ts(' with error ') . $e->getMessage()
              . ts('Invoice Pull failed');
          }
        }
      }
      if ($errors) {
        // Since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all invoices were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return TRUE;
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      throw new CRM_Core_Exception('Invoice Pull aborted due to throttling by Xero');
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
   * @param int $limit
   *   Number of invoices to process
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function push($params, $limit = 25) {
    try {
      $criteria = array(
        'accounts_needs_update' => 1,
        'plugin' => 'xero',
        // Sort by error data to try untried ones first.
        'options' => array(
          'sort' => 'error_data',
          'limit' => $limit,
        ),
      );
      if (!empty($params['contribution_id'])) {
        $criteria['contribution_id'] = $params['contribution_id'];
        unset($criteria['accounts_needs_update']);
      }
      $records = civicrm_api3('account_invoice', 'get', $criteria);
      $errors = array();

      //@todo pass limit through from params to get call
      foreach ($records['values'] as $record) {
        try {
          $accountsInvoiceID = isset($record['accounts_invoice_id']) ? $record['accounts_invoice_id'] : NULL;
          $contributionID = $record['contribution_id'];
          $civiCRMInvoice = civicrm_api3('account_invoice', 'getderived', array(
            'id' => $contributionID
          ));
          $civiCRMInvoice = $civiCRMInvoice['values'][$contributionID];
          if (empty($civiCRMInvoice) || $civiCRMInvoice['contribution_status_id'] == 3) {
            $accountsInvoice = $this->mapCancelled($contributionID, $accountsInvoiceID);
          }
          else {
            $accountsInvoice = $this->mapToAccounts($civiCRMInvoice, $accountsInvoiceID);
          }
          $result = $this->getSingleton()->Invoices($accountsInvoice);
          $responseErrors = $this->validateResponse($result);
          if ($responseErrors) {
            if (in_array('Invoice not of valid status for modification', $responseErrors)) {
              // we can't update in Xero as it is approved or voided so let's not keep trying
              $record['accounts_needs_update'] = 0;
            }
            $record['error_data'] = json_encode($responseErrors);
          }
          else {
            $record['error_data'] = 'null';
            if (empty($record['accounts_invoice_id'])) {
              $record['accounts_invoice_id'] = $result['Invoices']['Invoice']['InvoiceID'];
            }
            $record['accounts_modified_date'] = $result['Invoices']['Invoice']['UpdatedDateUTC'];
            $record['accounts_data'] = json_encode($result['Invoices']['Invoice']);
            $record['accounts_status_id'] = $this->mapStatus($result['Invoices']['Invoice']['Status']);
            $record['accounts_needs_update'] = 0;
          }
          //this will update the last sync date & anything hook-modified
          unset($record['last_sync_date']);
          if (empty($record['accounts_modified_date'])) {
            unset($record['accounts_modified_date']);
          }
          civicrm_api3('account_invoice', 'create', $record);
        }
        catch (CiviCRM_API3_Exception $e) {
          $errors[] = ts('Failed to store ') . $record['contribution_id'] . ' (' . $record['accounts_contact_id'] . ' )'
            . ts(' with error ') . $e->getMessage() . print_r($responseErrors, TRUE)
            . ts('Invoice Push failed');
        }
      }
      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all invoices were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return TRUE;
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      throw new CRM_Core_Exception('Invoice Push aborted due to throttling by Xero');
    }
  }

  /**
   * Map civicrm Array to Accounts package field names.
   *
   * @param array $invoiceData - require
   *  contribution fields
   *   - line items
   *   - receive date
   *   - source
   *   - contact_id
   * @param $accountsID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  function mapToAccounts($invoiceData, $accountsID) {

    $defaultAccountCode = civicrm_api('setting', 'getvalue', array(
      'group' => 'Xero Settings',
      'name' => 'xero_default_revenue_account',
      'version' => 3,
    ));

    $lineItems = array();
    foreach ($invoiceData['line_items'] as $lineItem) {
      $lineItems[] = array(
        "Description" => $lineItem['display_name'] . ' ' . str_replace(array('&nbsp;'), ' ', $lineItem['label']),
        "Quantity"    => $lineItem['qty'],
        "UnitAmount"  => $lineItem['unit_price'],
        "AccountCode" => !empty($lineItem['accounting_code']) ? $lineItem['accounting_code'] : $defaultAccountCode,
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

  function mapCancelled($contributionID, $accounts_invoice_id) {
    $newInvoice = array(
      'Invoice' => array(
        'InvoiceID'     => $accounts_invoice_id,
        'InvoiceNumber' => $contributionID,
        'Type'          => 'ACCREC',
        'Reference' =>  'Cancelled',
        'Date'  => date('Y-m-d',strtotime(now)),
        'DueDate'  => date('Y-m-d',strtotime(now)),
        'Status'  => 'DRAFT',
        'LineAmountTypes' =>'Exclusive',
        'LineItems' => array(
          'LineItem' => array(
            'Description' => 'Cancelled',
            'Quantity' => 0,
            'UnitAmount'=> 0,
            'AccountCode'=> 200,
          )
        ),
      )
    );
    return $newInvoice;
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
    if(empty($invoice['LineItems'])) {
      return;
    }
    foreach ($invoice['LineItems']['LineItem'] as $lineItems) {
      if(array_key_exists('LineItem', $lineItems)) {
        // multiple line items  - need to go one deeper
        foreach ($lineItems as $lineItem) {
          $this->validateTrackingCategory($lineItem);
        }
      }
      else {
        $this->validateTrackingCategory($lineItems);
      }
    }
  }

  /**
   * Check values in Line Item against retrieved list of Tracking Categories
   * @param unknown $lineItem
   * @throws CRM_Core_Exception
   */
  function validateTrackingCategory($lineItem) {
    if(empty($lineItem['TrackingCategory'])) {
      return;
    }
    static $trackingOptions = array();
    if(empty($trackingOptions)){
      $trackingOptions = civicrm_api3('xerosync', 'trackingcategorypull', array());
      $trackingOptions = $trackingOptions['values'];
    }
    foreach ($lineItem['TrackingCategory'] as $tracking) {
      if(!array_key_exists($tracking['Name'], $trackingOptions)
      || !in_array($tracking['Option'], $trackingOptions[$tracking['Name']])) {
        throw new CRM_Core_Exception(ts('Tracking Category Does Not Exist ') . $tracking['Name'] . ' ' . $tracking['Option'],'invalid_tracking', $tracking);
      }
    }
  }
}
