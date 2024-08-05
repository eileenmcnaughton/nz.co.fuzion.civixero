<?php

use CRM_Civixero_ExtensionUtil as E;

/**
 * Class CRM_Civixero_Invoice.
 *
 * This class provides the functions to push invoices to Xero and pull them
 * from Xero. Invoices pulled from Xero are stored in the civicrm_account_invoice
 * table. The functionality to handle them from there is in the
 * civicrm_account_sync extension.
 */
class CRM_Civixero_Invoice extends CRM_Civixero_Base {

  /**
   * Name in Xero of entity.
   *
   * @var string
   */
  protected $xero_entity = 'Invoice';

  /**
   * Default account code to be used when another cannot be identified.
   *
   * @var string
   */
  protected $default_account_code;

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $params
   *
   * @return int
   * @throws CRM_Core_Exception
   */
  public function pull(array $params): int {
    $xeroParams = ['Type' => 'ACCREC'];
    $filter = $params['invoice_number'] ?? FALSE;
    try {
      CRM_Civixero_Base::isApiRateLimitExceeded(TRUE);

      $count = 0;
      $result = $this
        ->getSingleton($params['connector_id'])
        ->Invoices($filter, $this->formatDateForXero($params['start_date']), $xeroParams);
      if (!is_array($result)) {
        throw new API_Exception('Sync Failed', 'xero_retrieve_failure', (array) $result);
      }
      $errors = [];
      if (!empty($result['Invoices'])) {
        $invoices = $result['Invoices']['Invoice'];
        if (isset($invoices['InvoiceID'])) {
          // The return syntax puts the contact only level higher up when only one contact is involved.
          $invoices = [$invoices];
        }
        $prefix = $this->getSetting('xero_invoice_number_prefix');
        if (!isset($prefix)) {
          $prefix = '';
        }

        foreach ($invoices as $invoice) {
          $save = TRUE;
          // Strip out the invoice number prefix if present.
          $contributionID = preg_replace("/^\Q{$prefix}\E/", '', CRM_Utils_Array::value('InvoiceNumber', $invoice));
          $params = [
            'contribution_id' => $contributionID,
            'accounts_modified_date' => $invoice['UpdatedDateUTC'],
            'plugin' => 'xero',
            'accounts_invoice_id' => $invoice['InvoiceID'],
            'accounts_data' => json_encode($invoice),
            'accounts_status_id' => $this->mapStatus($invoice['Status']),
            'accounts_needs_update' => 0,
            'connector_id' => $params['connector_id'],
          ];
          CRM_Accountsync_Hook::accountPullPreSave('invoice', $invoice, $save, $params);
          if (!$save) {
            continue;
          }
          try {
            $params['id'] = civicrm_api3('AccountInvoice', 'getvalue', [
              'return' => 'id',
              'accounts_invoice_id' => $invoice['InvoiceID'],
              'plugin' => $this->_plugin,
              'connector_id' => $params['connector_id'],
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
            // this is an update - but lets just check the contact id doesn't exist in the account_contact table first
            // e.g if a list has been generated but not yet pushed
            try {
              $existing = civicrm_api3('AccountInvoice', 'getsingle', [
                'return' => 'id',
                'contribution_id' => $contributionID,
                'plugin' => $this->_plugin,
              ]);
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
            civicrm_api3('AccountInvoice', 'create', $params);
            $count++;
          }
          catch (CiviCRM_API3_Exception $e) {
            $errors[] = E::ts('Failed to store %1 (%2)', [1 => $invoice['InvoiceNumber'], 2 => $invoice['InvoiceID']])
              . E::ts(' with error ') . $e->getMessage()
              . E::ts('Invoice Pull failed');
          }
        }
      }
      if ($errors) {
        // Since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return $count;
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
   * @return int
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function push($params, $limit = 10) {
    try {
      CRM_Civixero_Base::isApiRateLimitExceeded(TRUE);
      $records = $this->getContributionsRequiringPushUpdate($params, $limit);
      $errors = [];

      $count = 0;
      foreach ($records['values'] as $record) {
        try {
          $accountsInvoice = $this->getAccountsInvoice($record);
          if ($accountsInvoice === FALSE) {
            // Hook accountPushAlterMapped might set $accountsInvoice to FALSE if we should not sync
            continue;
          }
          $result = $this->pushToXero($accountsInvoice, $params['connector_id']);
          $responseErrors = $this->savePushResponse($result, $record);
          $count++;
        }
        catch (CiviCRM_API3_Exception $e) {
          $errors[] = E::ts('Failed to store %1 (%2)', [1 => $record['contribution_id'], 2 => $record['accounts_contact_id']])
            . E::ts(' with error ') . $e->getMessage() . print_r($responseErrors ?? [], TRUE)
            . E::ts('%1 Push failed', [1 => $this->xero_entity]);
        }
      }
      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return $count;
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      throw new CRM_Core_Exception($this->xero_entity . ' Push aborted due to throttling by Xero');
    }
  }

  /**
   * Map CiviCRM array to Accounts package field names.
   *
   * @param array $invoiceData - require
   *  contribution fields
   *   - line items
   *   - receive date
   *   - source
   *   - contact_id
   * @param int $accountsID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  protected function mapToAccounts($invoiceData, $accountsID) {
    // Get the tax mode from the CiviCRM setting. This should be 'exclusive' if
    // tax is enabled (but for historical reasons we force that later on).
    $line_amount_types = Civi::settings()->get('xero_tax_mode');
    $total_amount = 0;
    $lineItems = [];
    foreach ($invoiceData['line_items'] as $lineItem) {
      $lineItems[] = [
        'Description' => $lineItem['display_name'] . ' ' . str_replace(['&nbsp;'], ' ', $lineItem['label']),
        // Xero does not like negative quantity so for a refund make the price negative instead.
        'Quantity' => abs($lineItem['qty']),
        'UnitAmount' => $lineItem['qty'] >= 0 ? $lineItem['unit_price'] : (- $lineItem['unit_price']),
        'AccountCode' => !empty($lineItem['accounting_code']) ? $lineItem['accounting_code'] : $this->getDefaultAccountCode(),
      ];
      $total_amount += $lineItem['qty'] * $lineItem['unit_price'];

      // Historically 'tax_amount' might come at us as NULL, the empty string,
      // or a false numeric, but now it seems to be a string. '0.00' casts to
      // true but is equal to zero, so we have to check it.
      if (isset($lineItem['tax_amount']) && $lineItem['tax_amount'] && $lineItem['tax_amount'] !== '0.00') {
        // If we discover a non-zero tax_amount, switch to tax exclusive amounts.
        $line_amount_types = 'Exclusive';
      }
    }

    if ($total_amount < 0) {
      foreach ($lineItems as $index => $lineItem) {
        $lineItems[$index]['UnitAmount'] = -$lineItem['UnitAmount'];
      }
    }

    // Get default Invoice status
    $status = $this->settings->get('xero_default_invoice_status');

    $prefix = $this->settings->get('xero_invoice_number_prefix');
    if (empty($prefix)) {
      $prefix = '';
    }
    $new_invoice = [
      'Type' => ($total_amount > 0) ? 'ACCREC' : 'ACCPAY',
      'Contact' => [
        'ContactNumber' => $invoiceData['contact_id'],
      ],
      'Date' => substr($invoiceData['receive_date'], 0, 10),
      'DueDate' => substr($invoiceData['receive_date'], 0, 10),
      'Status' => $status,
      'InvoiceNumber' => $prefix . $invoiceData['id'],
      'CurrencyCode' => $invoiceData['currency'],
      'Reference' => $invoiceData['display_name'] . ' ' . $invoiceData['contribution_source'],
      'LineAmountTypes' => $line_amount_types,
      'LineItems' => ['LineItem' => $lineItems],
    ];

    /* Use due date and period from the invoice settings when available. */
    $invoiceDueDate = Civi::settings()->get('invoice_due_date');
    $invoiceDueDatePeriod = Civi::settings()->get('invoice_due_date_period');
    if ($invoiceDueDate && $invoiceDueDatePeriod !== 'select') {
      $new_invoice['DueDate'] = strftime('%Y-%m-%d', strtotime($invoiceData['receive_date'] . ' + ' . $invoiceDueDate . ' ' . $invoiceDueDatePeriod));
    }

    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('invoice', $invoiceData, $proceed, $new_invoice);
    if (!$proceed) {
      return FALSE;
    }

    $this->validatePrerequisites($new_invoice);
    return [$new_invoice];
  }

  /**
   * Map fields for a cancelled contribution to be updated to Xero.
   *
   * @param int $contributionID
   * @param string|null $accounts_invoice_id
   *
   * @return array
   */
  protected function mapCancelled(int $contributionID, ?string $accounts_invoice_id): array {
    return [
      'Invoice' => [
        'InvoiceID' => $accounts_invoice_id,
        'InvoiceNumber' => $contributionID,
        'Type' => 'ACCREC',
        'Reference' => 'Cancelled',
        'Date' => date('Y-m-d'),
        'DueDate' => date('Y-m-d'),
        'Status' => 'DRAFT',
        'LineAmountTypes' => 'Exclusive',
        'LineItems' => [
          'LineItem' => [
            'Description' => 'Cancelled',
            'Quantity' => 0,
            'UnitAmount' => 0,
            'AccountCode' => $this->getDefaultAccountCode(),
          ],
        ],
      ],
    ];
  }

  /**
   * Map Xero Status values against CiviCRM status values.
   *
   * @param string $status
   *   Status string from Xero.
   *
   * @return int
   *   CiviCRM equivalent status ID.
   */
  protected function mapStatus($status) {
    $statuses = [
      'PAID' => 1,
      'DELETED' => 3,
      'VOIDED' => 3,
      'DRAFT' => 2,
      'AUTHORISED' => 2,
      'SUBMITTED' => 2,
    ];
    return $statuses[$status];
  }

  /**
   * Validate an invoice by checking the tracking category exists (if set).
   *
   * @param array $invoice array ready for Xero
   *
   * @throws \CRM_Core_Exception
   */
  protected function validatePrerequisites($invoice) {
    if (empty($invoice['LineItems'])) {
      return;
    }
    foreach ($invoice['LineItems']['LineItem'] as $lineItems) {
      if (array_key_exists('LineItem', $lineItems)) {
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
   * Check values in Line Item against retrieved list of Tracking Categories.
   *
   * (Since this was written Xero exposed creating tracking categories via
   * the api so potentially we could now create rather than throw an exception if
   * the category does not exist).
   *
   * @param array $lineItem
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function validateTrackingCategory($lineItem) {
    if (empty($lineItem['TrackingCategory'])) {
      return;
    }
    static $trackingOptions = [];
    if (empty($trackingOptions)) {
      $trackingOptions = civicrm_api3('civixero', 'trackingcategorypull', []);
      $trackingOptions = $trackingOptions['values'];
    }
    foreach ($lineItem['TrackingCategory'] as $tracking) {
      if (!array_key_exists($tracking['Name'], $trackingOptions)
        || !in_array($tracking['Option'], $trackingOptions[$tracking['Name']])) {
        throw new CRM_Core_Exception(ts('Tracking Category Does Not Exist ') . $tracking['Name'] . ' ' . $tracking['Option'], 'invalid_tracking', $tracking);
      }
    }
  }

  /**
   * Get contributions marked as needing to be pushed to the accounts package.
   *
   * We sort by error data to get the ones that have not yet been attempted first.
   * Otherwise we can wind up endlessly retrying the same failing records.
   *
   * @param array $params
   * @param int $limit
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getContributionsRequiringPushUpdate($params, $limit) {
    $criteria = [
      'accounts_needs_update' => 1,
      'plugin' => 'xero',
      'connector_id' => $params['connector_id'],
      'accounts_status_id' => ['NOT IN' => [3]],
      'options' => [
        'sort' => 'error_data',
        'limit' => $limit,
      ],
    ];
    if (!empty($params['contribution_id'])) {
      $criteria['contribution_id'] = $params['contribution_id'];
      unset($criteria['accounts_needs_update']);
    }

    return civicrm_api3('AccountInvoice', 'get', $criteria);
  }

  /**
   * Get invoice formatted for Xero.
   *
   * @param array $record
   *
   * @return array|false
   * @throws \CiviCRM_API3_Exception
   */
  protected function getAccountsInvoice(array $record) {
    if ($record['accounts_status_id'] == 3) {
      return FALSE;
    }

    $accountsInvoiceID = isset($record['accounts_invoice_id']) ? $record['accounts_invoice_id'] : NULL;
    $contributionID = $record['contribution_id'];
    $civiCRMInvoice = civicrm_api3('AccountInvoice', 'getderived', [
      'id' => $contributionID,
    ]);

    $civiCRMInvoice = $civiCRMInvoice['values'][$contributionID];
    $statuses = civicrm_api3('Contribution', 'getoptions', ['field' => 'contribution_status_id']);
    $contributionStatus = $statuses['values'][$civiCRMInvoice['contribution_status_id']];
    $cancelledStatuses = ['Failed', 'Cancelled'];

    if (empty($civiCRMInvoice) || in_array($contributionStatus, $cancelledStatuses)) {
      return $this->mapCancelled($contributionID, $accountsInvoiceID);
    }

    return $this->mapToAccounts($civiCRMInvoice, $accountsInvoiceID);
  }

  /**
   * Get default account code to fall back to.
   *
   * @return array|int
   */
  protected function getDefaultAccountCode() {
    if (empty($this->default_account_code)) {
      $this->default_account_code = Civi::settings()->get('xero_default_revenue_account');
    }
    return $this->default_account_code;
  }

  /**
   * Save outcome from the push attempt to the civicrm_accounts_invoice table.
   *
   * @param array $result
   * @param array $record
   *
   * @return array
   *   Array of any errors
   *
   * @throws \CRM_Civixero_Exception_XeroThrottle
   * @throws \CiviCRM_API3_Exception
   */
  protected function savePushResponse($result, $record) {
    if ($result === FALSE) {
      $responseErrors = [];
      $record['accounts_needs_update'] = 0;
    }
    else {
      $responseErrors = $this->validateResponse($result);
      if ($responseErrors) {
        if ($this->isNotUpdateCandidate($responseErrors)) {
          // we can't update in Xero as it is approved or voided so let's not keep trying
          $record['accounts_needs_update'] = 0;
        }
        $record['error_data'] = json_encode($responseErrors);
      }
      else {
        $record['error_data'] = 'null';
        if (isset($result['BankTransactions'])) {
          // For bank transactions this would be
          // $record['accounts_invoice_id'] = $result['Invoices']['Invoice']['InvoiceID'];
          $record['accounts_invoice_id'] = $result['BankTransactions']['BankTransaction']['BankTransactionID'];
          $record['accounts_modified_date'] = $result['BankTransactions']['BankTransaction']['UpdatedDateUTC'];
          $record['accounts_data'] = json_encode($result['BankTransactions']['BankTransaction']);
          $record['accounts_status_id'] = $this->mapStatus($result['BankTransactions']['BankTransaction']['Status']);
          $record['accounts_needs_update'] = 0;
        }
        else {
          if (empty($record['accounts_invoice_id']) && !empty($result['Invoices']['Invoice']['InvoiceID'])) {
            $record['accounts_invoice_id'] = $result['Invoices']['Invoice']['InvoiceID'];
          }
          $record['accounts_modified_date'] = $result['Invoices']['Invoice']['UpdatedDateUTC'];
          $record['accounts_data'] = json_encode($result['Invoices']['Invoice']);
          $record['accounts_status_id'] = $this->mapStatus($result['Invoices']['Invoice']['Status']);
          $record['accounts_needs_update'] = 0;
        }
      }
    }
    //this will update the last sync date & anything hook-modified
    unset($record['last_sync_date']);
    if (empty($record['accounts_modified_date']) || $record['accounts_modified_date'] == '0000-00-00 00:00:00') {
      unset($record['accounts_modified_date']);
    }
    civicrm_api3('AccountInvoice', 'create', $record);
    return $responseErrors;
  }

  /**
   * Does this response denote updating is not possible.
   *
   * @param array $responseErrors
   *
   * @return bool
   */
  protected function isNotUpdateCandidate($responseErrors) {
    return (bool) count(array_intersect($responseErrors, $this->getNotUpdateCandidateResponses()));
  }

  /**
   * Get a list of responses indicating the transaction cannot be updated.
   *
   * @return array
   */
  protected function getNotUpdateCandidateResponses(): array {
    return [
      'Invoice not of valid status for modification',
      ' Invoice not of valid status for modification This document cannot be edited as it has a payment or credit note allocated to it.',
    ];
  }

  /**
   * Push record to Xero.
   *
   * @param array|false $accountsInvoice
   *
   * @param int $connector_id
   *   ID of the connector (0 if nz.co.fuzion.connectors not installed.
   *
   * @return array|false
   */
  protected function pushToXero($accountsInvoice, $connector_id) {
    if ($accountsInvoice === FALSE) {
      return FALSE;
    }
    return $this->getSingleton($connector_id)->Invoices($accountsInvoice);
  }

  /**
   * Should transactions be split to go to different accounts based on the line items.
   *
   * Currently we just say 'yes' for bank transactions and 'no' for invoices but
   * in future we may do a setting for this. Although we don't particularly envisage
   * invoices ever being split.
   *
   * Splitting only works if the nz.co.fuzion.connectors extension is installed.
   *
   * @return bool
   */
  protected function isSplitTransactions(): bool {
    return FALSE;
  }

  /**
   * Get the Xero invoice statuses.
   *
   * This is accessed from the settings.
   *
   * @return array[]
   */
  public static function getInvoiceStatuses(): array {
    // @todo - can we get rid of the caps on 'id'?
    $selectTwoStyleResult = [
      [
        'id' => 'DRAFT',
        'name' => 'draft',
        'label' => E::ts('Draft'),
      ],
      [
        'id' => 'SUBMITTED',
        'name' => 'submitted',
        'label' => E::ts('Submitted'),
      ],

      [
        'id' => 'AUTHORISED',
        'name' => 'approved',
        'label' => E::ts('Approved'),
      ],
    ];
    // But we can't use that yet - see https://github.com/civicrm/civicrm-core/pull/25014
    $return = [];
    foreach ($selectTwoStyleResult as $result) {
      $return[$result['id']] = $result['label'];
    }
    return $return;
  }

  /**
   * Get the Xero tax modes.
   *
   * This is accessed from the settings.
   *
   * @return array[]
   */
  public static function getTaxModes(): array {
    // @todo - can we get rid of the caps on 'id'?
    $selectTwoStyleResult = [
      [
        'id' => 'Inclusive',
        'name' => 'inclusive',
        'label' => E::ts('Inclusive'),
      ],
      [
        'id' => 'Exclusive',
        'name' => 'exclusive',
        'label' => E::ts('Exclusive'),
      ],
    ];
    // But we can't use that yet - see https://github.com/civicrm/civicrm-core/pull/25014
    $return = [];
    foreach ($selectTwoStyleResult as $result) {
      $return[$result['id']] = $result['label'];
    }
    return $return;
  }

}
