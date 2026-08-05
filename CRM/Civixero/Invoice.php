<?php

use Civi\Api4\Payment;
use CRM_Civixero_ExtensionUtil as E;
use Civi\Api4\AccountInvoice;
use Civi\Api4\AccountContact;
use Civi\Api4\Contribution;
use XeroAPI\XeroPHP\AccountingObjectSerializer;
use XeroAPI\XeroPHP\Models\Accounting\Invoice;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;

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
  protected string $xero_entity = 'Invoice';

  /**
   * Default account code to be used when another cannot be identified.
   *
   * @var string
   */
  protected $default_account_code;


  public function pullFromXero(
    bool $includeArchived,
    bool $summaryOnly,
    string $searchTerm,
    int $page,
    int $pageSize,
    string $ifModifiedSinceDateTime,
    string $invoiceIDs,
    string $invoiceNumbers,
    string $xeroContactIDs
  ): array {
    $where = 'TYPE=="' . Invoice::TYPE_ACCREC . '"';

    $xeroTenantId = $this->getTenantID();
    $ifModifiedSince = new DateTime($ifModifiedSinceDateTime);
    if (!empty(\Civi::settings()->get('account_sync_contribution_day_zero'))) {
      // Never retrieve before date zero
      $minDateFrom = new \DateTime(\Civi::settings()->get('account_sync_contribution_day_zero'));
      if ($minDateFrom > $ifModifiedSince) {
        $ifModifiedSince = $minDateFrom;
      }
    }
    // $where = "Status=="' . \XeroAPI\XeroPHP\Models\Accounting\Invoice::STATUS_DRAFT . '"";
    // $where = $filters['where'] ?? NULL;
    $order = "Date ASC";
    $statuses = ['DRAFT', 'SUBMITTED', 'AUTHORISED', 'PAID'];
    $createdByMyApp = FALSE;
    $unitdp = 2;

    try {
      $xeroInvoices = $this->getAccountingApiInstance()->getInvoices(
        $xeroTenantId, $ifModifiedSince, $where, $order, $invoiceIDs, $invoiceNumbers, $xeroContactIDs,
        $statuses, $page, $includeArchived, $createdByMyApp, $unitdp, $summaryOnly, $pageSize
      );
      foreach ($xeroInvoices->getInvoices() as $xeroInvoice) {
        /**
         * @var \XeroAPI\XeroPHP\Models\Accounting\Invoice $xeroInvoice
         */
        foreach ($xeroInvoice::attributeMap() as $localName => $originalName) {
          $getter = 'get' . $originalName;
          switch ($localName) {
            case 'updated_date_utc':
            case 'date':
            case 'due_date':
              $dateGetter = $getter . 'AsDate';
              $invoice[$localName] = $xeroInvoice->$dateGetter()->format('Y-m-d H:i:s');
              break;

            default:
              // Same nested-model flattening fix as Contact::pullFromXero -
              // raw json_encode() turns SDK models into {} in accounts_data.
              $invoice[$localName] = AccountingObjectSerializer::sanitizeForSerialization($xeroInvoice->$getter());
          }
        }
        $invoices[$invoice['invoice_id']] = $invoice;
      }
    }
    catch (\InvalidArgumentException $e) {
      // This means there are no invoices returned for the requested page. That's ok!
      return [];
    }
    catch (\Exception $e) {
      \Civi::log('civixero')->error('Exception when calling AccountingApi->getInvoices: ' . $e->getMessage());
      throw $e;
    }
    return $invoices ?? [];
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
        $invoicePull = \Civi\Api4\Xero::invoicePull(FALSE)
          ->setConnectorID($params['connector_id'] ?? 0)
          ->setPage($page)
          ->setPageSize($pageSize);
        if (!empty($params['xero_contact_id'])) {
          $invoicePull->setXeroContactIDs($params['xero_contact_id']);
        }
        if (!empty($params['xero_invoice_id'])) {
          $invoicePull->setXeroInvoiceIDs($params['xero_invoice_id']);
        }
        if (!empty($params['invoice_number'])) {
          $invoicePull->setXeroInvoiceNumbers($params['invoice_number']);
        }
        if (empty($params['xero_contact_id']) && empty($params['xero_invoice_id']) && empty($params['xero_invoice_number'])) {
          // Ignore start/modified date if we specified IDs
          $invoicePull->setIfModifiedSinceDateTime($params['start_date']);
        }
        $invoices = $invoicePull->execute()->getArrayCopy();
        if (empty($invoices)) {
          break;
        }
        $this->processPull($invoices, $params['connector_id'] ?? 0, $params['create_contributions_in_civicrm'] ?? FALSE);
        unset($invoices);
        $page++;
      }
    }
    catch (\Throwable $e) {
      \Civi::log('civixero')->error('CiviXero: Error when running Invoice Pull: ' . $e->getMessage());
      if ($e->getCode() === 403) {
        throw new CRM_Core_Exception('Authentication with Xero failed');
      }
    }
  }

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * @param array $invoices
   * @param int $connectorID
   * @param bool $createContributionInCiviCRM
   *
   * @return int
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function processPull(array $invoices, int $connectorID, bool $createContributionInCiviCRM = FALSE) {
    $count = 0;
    $errors = $ids = [];
    foreach ($invoices as $xeroInvoiceID => $xeroInvoice) {
      $contributionID = NULL;
      $accountInvoiceParams = [
        'plugin' => $this->_plugin,
        'connector_id' => $connectorID,
        'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($xeroInvoice['updated_date_utc'])),
        'accounts_invoice_id' => $xeroInvoice['invoice_id'],
        'accounts_data' => json_encode($xeroInvoice),
        'accounts_status_id' => $this->mapXeroInvoiceStatusToAccountInvoiceStatusID($xeroInvoice['status']),
        'accounts_needs_update' => FALSE,
      ];

      $prefix = $this->getSetting('xero_invoice_number_prefix') ?: '';
      // If we have no prefix we don't know if the InvoiceNumber was generated by Xero or CiviCRM, so we can't use it.
      if (!empty($prefix) && !empty($xeroInvoice['invoice_number']) && (str_starts_with($xeroInvoice['invoice_number'], $prefix))) {
        // Strip out the invoice number prefix if present.
        $contributionID = preg_replace("/^\Q{$prefix}\E/", '', $xeroInvoice['invoice_number'] ?? NULL);
        // Xero sets InvoiceNumber = InvoiceID (accounts_invoice_id) if not set by CiviCRM.
        // We can only use it if it is an integer (map it to CiviCRM contribution_id).
        $contributionID = CRM_Utils_Type::validate($contributionID, 'Integer', FALSE);
        if ($contributionID) {
          $accountInvoiceParams['contribution_id'] = $contributionID;
        }
      }

      $save = TRUE;
      CRM_Accountsync_Hook::accountPullPreSave('invoice', $xeroInvoice, $save, $accountInvoiceParams);
      if (!$save) {
        continue;
      }

      $accountInvoice = AccountInvoice::get(FALSE)
        ->addWhere('plugin', '=', $this->_plugin)
        ->addWhere('connector_id', '=', $connectorID)
        ->addWhere('accounts_invoice_id', '=', $xeroInvoice['invoice_id'])
        ->execute()
        ->first();
      try {
        if (empty($accountInvoice)) {
          // Invoice is in Xero but (accounts_invoice_id) does not exist in account_invoice table
          // Note $contributionId will be invalid if it was generated at Xero and did not exist in CiviCRM because it is
          //   derived from Xero invoice ID without prefix.
          // So we can't use contribution ID - remove it, and then we'll record a new entry in account_invoice with Xero invoice.
          // This could be manually reconciled by adding a contribution ID.
          // Create a new AccountInvoice record
          unset($accountInvoiceParams['contribution_id']);
          $newAccountInvoice = AccountInvoice::create(FALSE)
            ->setValues($accountInvoiceParams)
            ->execute()
            ->first();
          $ids[] = $newAccountInvoice['id'];
        }
        else {
          // Update existing AccountInvoice record
          $modifiedFieldKeys = [
            'accounts_modified_date',
            'accounts_status_id',
            'accounts_needs_update',
          ];
          foreach ($modifiedFieldKeys as $key) {
            // Every time we do an "update" last_sync_date is updated which triggers an entry in log_civicrm_account_contact.
            // So check if anything actually changed before updating.
            if ($accountInvoiceParams[$key] !== $accountInvoice[$key]) {
              // Something changed, update AccountInvoice in DB
              if (!empty($accountInvoice['contribution_id'])) {
                // If the accountInvoice already has a contribution ID don't try to overwrite it with the one we derived from InvoiceNumber.
                // Probably we manually reconciled it at some point.
                unset($accountInvoiceParams['contribution_id']);
              }
              if (isset($accountInvoiceParams['contribution_id']) && empty(Contribution::get(FALSE)->addWhere('id', '=', $accountInvoiceParams['contribution_id'])->execute()->first())) {
                // This happens if we deleted the contribution in CiviCRM
                $accountInvoiceParams['error_data'] = json_encode(['error' => "ContributionID {$accountInvoiceParams['contribution_id']} not found in CiviCRM. If you deleted it you can mark this as resolved."]);
                unset($accountInvoiceParams['contribution_id']);
              }
              $newAccountInvoice = AccountInvoice::update(FALSE)
                ->setValues($accountInvoiceParams)
                ->addWhere('id', '=', $accountInvoice['id'])
                ->execute()
                ->first();
              $ids[] = $newAccountInvoice['id'];
              break;
            }
          }
        }
        if ($createContributionInCiviCRM) {
          $this->createContributionFromAccountsInvoice($xeroInvoice, $accountInvoiceParams);
        }
      }
      catch (CRM_Core_Exception $e) {
        $errors[] = E::ts('Failed to store %1 (%2)', [1 => $xeroInvoice['invoice_number'], 2 => $xeroInvoice['invoice_id']])
          . E::ts(' with error ') . $e->getMessage();
      }
    }
    if ($errors) {
      \Civi::log('xero')->warning('Not all records were saved {errors}', ['errors' => $errors]);
      // Since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(E::ts('Not all records were saved') . ': ' . print_r($errors, TRUE), 'incomplete', $errors);
    }
    if (!empty($ids)) {
      \Civi::log('xero')->info('Xero Invoice Pull: {count} IDs retrieved {ids}', ['count' => count($ids), 'ids' => implode(', ', $ids)]);
    }
    return $count;
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
   */
  public function push($params, $limit = 10) {
    $accountInvoices = $this->getAccountInvoicesToPush($params, $limit);
    if (empty($accountInvoices)) {
      return 0;
    }
    $errors = [];

    $count = 0;
    try {
      foreach ($accountInvoices as $accountInvoice) {
        try {
          $mappedAccountInvoice = $this->getMappedAccountInvoice($accountInvoice);
          if ($mappedAccountInvoice === NULL) {
            // Contact not yet synced to Xero — leave accounts_needs_update set and try again next run.
            continue;
          }
          if ($mappedAccountInvoice === FALSE) {
            // We need to set an error so that they are not selected for push next time otherwise we'll keep trying to push the same ones
            AccountInvoice::update(FALSE)
              ->addWhere('id', '=', $accountInvoice['id'])
              ->addValue('error_data', json_encode(['error' => 'Ignored via accountPushAlterMapped hook']))
              ->addValue('is_error_resolved', FALSE)
              ->addValue('accounts_needs_update', FALSE)
              ->execute();
            // Hook accountPushAlterMapped might set $accountsInvoice to FALSE if we should not sync
            continue;
          }
        }
        catch (CRM_Core_Exception $e) {
          // We need to set an error so that they are not selected for push next time otherwise we'll keep trying to push the same ones
          AccountInvoice::update(FALSE)
            ->addWhere('id', '=', $accountInvoice['id'])
            ->addValue('error_data', json_encode(['error' => $e->getMessage()]))
            ->addValue('accounts_needs_update', FALSE)
            ->execute();
          continue;
        }
        try {
          $pushResult = $this->pushToXero($mappedAccountInvoice, $params['connector_id']);
          $responseErrors = $this->savePushResponse($pushResult, $accountInvoice);
          $count++;
        }
        catch (CRM_Core_Exception $e) {
          $errorMessage = E::ts('Failed to push contributionID: %1', [1 => $accountInvoice['contribution_id']])
            . E::ts('Error: ') . $e->getMessage() . print_r($responseErrors ?? [], TRUE)
            . E::ts('%1 Push failed', [1 => $this->xero_entity]);

          AccountInvoice::update(FALSE)
            ->addWhere('id', '=', $accountInvoice['id'])
            ->addValue('is_error_resolved', FALSE)
            ->addValue('error_data', json_encode([
              'error' => $e->getMessage(),
              'error_data' => $accountInvoice['error_data'],
            ]))
            ->addValue('accounts_data', json_encode($accountInvoice))
            ->execute();
          $errors[] = $errorMessage;
        }
      }
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      $errors[] = ($this->xero_entity . ' Push aborted due to throttling by Xero');
      CRM_Civixero_Base::setApiRateLimitExceeded($e->getRetryAfter());
    }
    if ($errors) {
      // since we expect this to wind up in the job log we'll print the errors
      throw new CRM_Core_Exception(ts('Not all records were saved') . print_r($errors, TRUE), 'incomplete', $errors);
    }
    return $count;

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
   * @param ?string $xeroInvoiceUUID
   *   The Xero invoice uuid.
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   * @throws \CRM_Core_Exception
   */
  protected function mapToAccounts(array $invoiceData, ?string $xeroInvoiceUUID) {
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
        'UnitAmount' => $lineItem['qty'] >= 0 ? $lineItem['unit_price'] : (-$lineItem['unit_price']),
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
        'ContactID' => $invoiceData['accounts_contact_id'],
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
    if (!empty($xeroInvoiceUUID)) {
      $new_invoice['InvoiceID'] = $xeroInvoiceUUID;
    }

    /* Use due date and period from the invoice settings when available. */
    $invoiceDueDate = Civi::settings()->get('invoice_due_date');
    $invoiceDueDatePeriod = Civi::settings()->get('invoice_due_date_period');
    if ($invoiceDueDate && $invoiceDueDatePeriod !== 'select') {
      $new_invoice['DueDate'] = strftime('%Y-%m-%d', strtotime($invoiceData['receive_date'] . ' + ' . $invoiceDueDate . ' ' . $invoiceDueDatePeriod));
    }

    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('invoice', $invoiceData, $proceed, $new_invoice);
    if (!$proceed) {
      throw new CRM_Core_Exception('Ignored via accountPushAlterMapped hook');
    }

    $this->validatePrerequisites($new_invoice);
    return [$new_invoice];
  }

  /**
   * Map fields for a cancelled contribution to be updated to Xero.
   *
   * @param int $contributionID
   * @param ?string $xeroInvoiceUUID
   *    The Xero invoice uuid.
   *
   * @return array
   */
  protected function mapCancelled(int $contributionID, ?string $xeroInvoiceUUID): array {
    $prefix = $this->settings->get('xero_invoice_number_prefix') ?: '';
    return [
      'Invoice' => [
        'InvoiceID' => $xeroInvoiceUUID,
        'InvoiceNumber' => $prefix . $contributionID,
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
   * See also \Civi\Api4\Action\AccountInvoice\GetAccountsDataXero::mapStatusToContributionStatus()
   *
   * @param string $status
   *   Status string from Xero.
   *
   * @return int
   *   CiviCRM equivalent status ID.
   */
  protected function mapXeroInvoiceStatusToAccountInvoiceStatusID(string $status): int {
    $accountsStatusIDs = array_column(Civi::entity('AccountInvoice')->getOptions('accounts_status_id'), 'id', 'name');

    $statuses = [
      'PAID' => $accountsStatusIDs['completed'],
      'DELETED' => $accountsStatusIDs['cancelled'],
      'VOIDED' => $accountsStatusIDs['cancelled'],
      'DRAFT' => $accountsStatusIDs['pending'],
      'AUTHORISED' => $accountsStatusIDs['pending'],
      'SUBMITTED' => $accountsStatusIDs['pending'],
    ];
    if (!isset($statuses[$status])) {
      // Unknown/new Xero status: default to the inert 'pending' rather than
      // crashing the pull.
      \Civi::log('civixero')->warning('Unknown Xero invoice status ' . $status . ' - defaulting to pending');
      return $accountsStatusIDs['pending'];
    }
    return $statuses[$status];
  }

  /**
   * Validate an invoice by checking the tracking category exists (if set).
   *
   * @param array $invoice array ready for Xero
   *
   * @throws \CRM_Core_Exception
   */
  protected function validatePrerequisites($invoice): void {
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
   */
  protected function validateTrackingCategory($lineItem): void {
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
   * Otherwise, we can wind up endlessly retrying the same failing records.
   *
   * @param array $params
   * @param int $limit
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getAccountInvoicesToPush(array $params, int $limit): array {
    $accountInvoices = AccountInvoice::get(FALSE)
      ->addWhere('plugin', '=', 'xero')
      ->addWhere('connector_id', '=', $params['connector_id'])
      ->addClause('OR', ['accounts_status_id', 'IS NULL'], ['accounts_status_id:name', 'NOT IN', ['cancelled']])
      ->addOrderBy('error_data')
      ->setLimit($limit);

    if (!empty($params['contribution_id'])) {
      $accountInvoices->addWhere('contribution_id', '=', $params['contribution_id']);
    }
    else {
      $accountInvoices->addClause('OR', ['error_data', 'IS NULL'], ['is_error_resolved', '=', TRUE]);
      $accountInvoices->addWhere('accounts_needs_update', '=', TRUE);
    }
    return (array) $accountInvoices->execute();
  }

  /**
   * Get invoice formatted for Xero.
   *
   * @param array $record
   *
   * @return array|false|null
   *   Invoice payload for Xero, FALSE to skip permanently, NULL to defer until later.
   * @throws \CRM_Core_Exception
   */
  protected function getMappedAccountInvoice(array $record): array|null|bool {
    if ($record['accounts_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Accountsync_BAO_AccountInvoice', 'accounts_status_id', 'cancelled')) {
      throw new CRM_Core_Exception('AccountInvoice is cancelled');
    }

    $xeroInvoiceUUID = $record['accounts_invoice_id'] ?? NULL;
    $contributionID = $record['contribution_id'];
    $civiCRMInvoice = civicrm_api3('AccountInvoice', 'getderived', [
      'id' => $contributionID,
    ])['values'][$contributionID] ?? [];

    $contributionStatusName = CRM_Core_PseudoConstant::getName('CRM_Contribute_DAO_Contribution', 'contribution_status_id', $civiCRMInvoice['contribution_status_id']);;
    $cancelledStatuses = ['Failed', 'Cancelled'];

    if (empty($civiCRMInvoice) || in_array($contributionStatusName, $cancelledStatuses)) {
      return $this->mapCancelled($contributionID, $xeroInvoiceUUID);
    }

    // New invoices need a Xero ContactID. If the contact has not been pushed yet,
    // defer (return NULL) so we do not write a permanent Guid-format error that
    // blocks automatic retries after the contact push job runs.
    // @see https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/issues/177
    if (empty($xeroInvoiceUUID) && empty($civiCRMInvoice['accounts_contact_id'])) {
      \Civi::log('civixero')->info('CiviXero: deferring invoice push for contribution {id} until contact is synced to Xero', [
        'id' => $contributionID,
      ]);
      return NULL;
    }

    return $this->mapToAccounts($civiCRMInvoice, $xeroInvoiceUUID);
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
   * @throws \CRM_Core_Exception
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
          $record['accounts_status_id'] = $this->mapXeroInvoiceStatusToAccountInvoiceStatusID($result['BankTransactions']['BankTransaction']['Status']);
          $record['accounts_needs_update'] = 0;
        }
        else {
          if (empty($record['accounts_invoice_id']) && !empty($result['Invoices']['Invoice']['InvoiceID'])) {
            $record['accounts_invoice_id'] = $result['Invoices']['Invoice']['InvoiceID'];
          }
          $record['accounts_modified_date'] = $result['Invoices']['Invoice']['UpdatedDateUTC'];
          $accountsData = $result['Invoices']['Invoice'];
          // It can get too long for the db if we include everything.
          unset($accountsData['Contact'], $accountsData['LineItems']);
          $record['accounts_data'] = json_encode($accountsData);
          $record['accounts_status_id'] = $this->mapXeroInvoiceStatusToAccountInvoiceStatusID($result['Invoices']['Invoice']['Status']);
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
    foreach ($responseErrors as $error) {
      foreach ($this->getNotUpdateCandidateResponses() as $noUpdateString) {
        if (str_contains($error, $noUpdateString)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Get a list of responses indicating the transaction cannot be updated.
   *
   * @return array
   */
  protected function getNotUpdateCandidateResponses(): array {
    return [
      'Invoice not of valid status for modification',
      'This document cannot be edited as it has a payment or credit note allocated to it.',
      'The status SUBMITTED cannot be applied to the invoice because it has payments or credit notes allocated to it.',
      'The status AUTHORISED cannot be applied to the invoice because it has payments or credit notes allocated to it.',
      'The status DRAFT cannot be applied to the invoice because it has payments or credit notes allocated to it.',
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
   * @throws \CRM_Core_Exception
   */
  protected function pushToXero($accountsInvoice, $connector_id) {
    if ($accountsInvoice === FALSE) {
      return FALSE;
    }
    $mapped = $this->normalizeMappedInvoice($accountsInvoice);
    try {
      return $this->pushViaApi($mapped);
    }
    catch (XeroThrottleException $e) {
      throw new CRM_Civixero_Exception_XeroThrottle($e->getMessage(), $e->getCode(), $e, $e->getRetryAfter());
    }
    catch (XeroException $e) {
      if (method_exists($e, 'getXML') && $e->getXML()) {
        return ArrayToXML::toArray($e->getXML());
      }
      // if now \Civi::log('xero')->warning('Failed push with {xml}', ['xml' => $e->getXML()]]
      throw new CRM_Core_Exception(
        'Synchronization error ' . $e->getMessage(),
        'xero_' . $e->getCode(),
        ['xml' => (method_exists($e, 'getXML') ? $e->getXML() : '')]
      );
    }
  }

  /**
   * Should transactions be split to go to different accounts based on the line items.
   *
   * Currently, we just say 'yes' for bank transactions and 'no' for invoices but
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
   * See \XeroAPI\XeroPHP\Models\Accounting\LineAmountTypes
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

  /**
   * @param array $invoice
   * @param array $accountInvoiceParams
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function createContributionFromAccountsInvoice(array $invoice, array $accountInvoiceParams): bool {
    $accountInvoiceParams = AccountInvoice::get(FALSE)
      ->addWhere('accounts_invoice_id', '=', $accountInvoiceParams['accounts_invoice_id'])
      ->execute()
      ->first();
    if (!empty($accountInvoiceParams['contribution_id'])) {
      // \Civi::log('civixero')->debug(__FUNCTION__ . ': AccountsInvoice is already linked to a contribution: ' . print_r($invoice, TRUE));
      return FALSE;
    }

    if ($accountInvoiceParams['accounts_status_id'] === CRM_Core_PseudoConstant::getKey('CRM_Accountsync_BAO_AccountInvoice', 'accounts_status_id', 'cancelled')) {
      // Invoice is voided/cancelled. Don't try to create in CiviCRM
      return FALSE;
    }

    /** @var XeroAPI\XeroPHP\Models\Accounting\Contact $invoice['contact'] */
    $accountsContactID = $invoice['contact']->getContactId() ?? NULL;
    if (empty($accountsContactID)) {
      $errorMessage = __FUNCTION__ . ': missing ContactID in AccountsInvoice';
      $this->recordAccountInvoiceError($accountInvoiceParams['id'], $errorMessage);
      \Civi::log('civixero')->debug($errorMessage . ': ' . print_r($invoice, TRUE));
      return FALSE;
    }

    $accountContact = AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', $accountsContactID)
      ->execute()
      ->first();
    if (empty($accountContact)) {
      $errorMessage = __FUNCTION__ . ': no AccountsContact found';
      $this->recordAccountInvoiceError($accountInvoiceParams['id'], $errorMessage);
      \Civi::log('civixero')->debug($errorMessage . ': ' . print_r($invoice, TRUE));
      return FALSE;
    }
    if (empty($accountContact['contact_id'])) {
      $errorMessage = __FUNCTION__ . ': AccountsContact is not matched to a CiviCRM Contact ID';
      $this->recordAccountInvoiceError($accountInvoiceParams['id'], $errorMessage);
      \Civi::log('civixero')->debug($errorMessage . ': ' . print_r($invoice, TRUE));
      return FALSE;
    }

    $lock = Civi::lockManager()->acquire('data.accountsync.createcontribution');
    if (!$lock->isAcquired()) {
      Civi::log()->warning(__FUNCTION__ . ': Could not acquire lock to create contribution');
      return FALSE;
    }
    \Civi::$statics['data.accountsync.createcontribution']['createnew'] = FALSE;
    $contribution = Contribution::create(FALSE)
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('contact_id', $accountContact['contact_id'])
      ->addValue('financial_type_id.name', 'Donation')
      ->addValue('receive_date', $invoice['date'])
      ->addValue('total_amount', $invoice['total'])
      ->addValue('currency', $invoice['currency_code'])
      ->addValue('source', 'Xero: ' . $invoice['invoice_number'] . ' ' . $invoice['reference'])
      ->execute()
      ->first();
    AccountInvoice::update(FALSE)
      ->addValue('contribution_id', $contribution['id'])
      ->addWhere('accounts_invoice_id', '=', $accountInvoiceParams['accounts_invoice_id'])
      ->addValue('error_data', NULL)
      ->addValue('is_error_resolved', TRUE)
      ->addValue('accounts_needs_update', FALSE)
      ->execute();
    $lock->release();
    unset(\Civi::$statics['data.accountsync.createcontribution']['createnew']);

    if ($accountInvoiceParams['accounts_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Accountsync_BAO_AccountInvoice', 'accounts_status_id', 'completed')) {
      Payment::create(FALSE)
        ->setNotificationForPayment(FALSE)
        ->setNotificationForCompleteOrder(FALSE)
        ->addValue('contribution_id', $contribution['id'])
        ->addValue('total_amount', $contribution['total_amount'])
        ->addValue('trxn_date', $contribution['receive_date'])
        ->execute();
    }
    elseif ($accountInvoiceParams['accounts_status_id'] === (int) CRM_Core_PseudoConstant::getKey('CRM_Accountsync_BAO_AccountInvoice', 'accounts_status_id', 'cancelled')) {
      Contribution::update(FALSE)
        ->addValue('contribution_status_id:name', 'Cancelled')
        ->addWhere('id', '=', $contribution['id'])
        ->execute();
    }

    return TRUE;
  }

  /**
   * @param string $accountInvoiceID
   * @param string $message
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function recordAccountInvoiceError(string $accountInvoiceID, string $message) {
    AccountInvoice::update(FALSE)
      ->addValue('error_data', $message)
      ->addValue('is_error_resolved', FALSE)
      ->addWhere('id', '=', $accountInvoiceID)
      ->execute();
  }

  /**
   * Normalise the three historical shapes produced by the mapping methods:
   * [$invoice] from mapToAccounts(), ['Invoice' => $invoice] from
   * mapCancelled(), or a bare associative invoice array (hook-altered).
   */
  private function normalizeMappedInvoice(array $accountsInvoice): array {
    if (isset($accountsInvoice['Invoice']) && is_array($accountsInvoice['Invoice'])) {
      return $accountsInvoice['Invoice'];
    }
    if (isset($accountsInvoice[0]) && is_array($accountsInvoice[0])) {
      return $accountsInvoice[0];
    }
    return $accountsInvoice;
  }

  /**
   * Build SDK LineItem models from the mapped LineItems array.
   *
   * Handles both a list of line-item arrays and the single associative
   * line-item shape used by mapCancelled(). Preserves hook-added
   * TrackingCategory, TaxType and ItemCode values.
   *
   * @return \XeroAPI\XeroPHP\Models\Accounting\LineItem[]
   */
  protected function buildSdkLineItems(array $mapped): array {
    $rows = $mapped['LineItems']['LineItem'] ?? [];
    if ($rows !== [] && !isset($rows[0])) {
      // Single associative line item (mapCancelled shape).
      $rows = [$rows];
    }
    $lineItems = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $lineItem = new \XeroAPI\XeroPHP\Models\Accounting\LineItem();
      if (isset($row['Description'])) {
        $lineItem->setDescription(mb_substr((string) $row['Description'], 0, 4000));
      }
      if (isset($row['Quantity'])) {
        $lineItem->setQuantity((float) $row['Quantity']);
      }
      if (isset($row['UnitAmount'])) {
        $lineItem->setUnitAmount((float) $row['UnitAmount']);
      }
      if (!empty($row['AccountCode'])) {
        $lineItem->setAccountCode((string) $row['AccountCode']);
      }
      if (!empty($row['TaxType'])) {
        $lineItem->setTaxType((string) $row['TaxType']);
      }
      if (isset($row['TaxAmount']) && $row['TaxAmount'] !== '') {
        $lineItem->setTaxAmount((float) $row['TaxAmount']);
      }
      if (!empty($row['ItemCode'])) {
        $lineItem->setItemCode((string) $row['ItemCode']);
      }
      if (!empty($row['TrackingCategory']) && is_array($row['TrackingCategory'])) {
        $trackingModels = [];
        foreach ($row['TrackingCategory'] as $tracking) {
          if (empty($tracking['Name'])) {
            continue;
          }
          $trackingModel = new LineItemTracking();
          $trackingModel->setName((string) $tracking['Name']);
          $trackingModel->setOption((string) ($tracking['Option'] ?? ''));
          $trackingModels[] = $trackingModel;
        }
        if ($trackingModels !== []) {
          $lineItem->setTracking($trackingModels);
        }
      }
      $lineItems[] = $lineItem;
    }
    return $lineItems;
  }

  /**
   * Build the embedded contact reference for an invoice/bank transaction.
   */
  protected function buildSdkContactRef(array $mapped): ?\XeroAPI\XeroPHP\Models\Accounting\Contact {
    if (empty($mapped['Contact']) || !is_array($mapped['Contact'])) {
      return NULL;
    }
    $contactRef = new \XeroAPI\XeroPHP\Models\Accounting\Contact();
    if (!empty($mapped['Contact']['ContactID'])) {
      $this->assertValidXeroGuid((string) $mapped['Contact']['ContactID'], 'Xero contact reference (ContactID)');
      $contactRef->setContactId($mapped['Contact']['ContactID']);
    }
    if (!empty($mapped['Contact']['ContactNumber'])) {
      $contactRef->setContactNumber((string) $mapped['Contact']['ContactNumber']);
    }
    return $contactRef;
  }

  /**
   * Extract per-object validation error messages from an SDK model.
   *
   * @param \XeroAPI\XeroPHP\Models\Accounting\Invoice|\XeroAPI\XeroPHP\Models\Accounting\BankTransaction $model
   *
   * @return string[]
   */
  protected function extractValidationErrors($model): array {
    $messages = [];
    foreach ($model->getValidationErrors() ?? [] as $validationError) {
      $messages[] = $validationError->getMessage();
    }
    return $messages;
  }

  /**
   * Push one invoice via AccountingApi::updateOrCreateInvoices.
   *
   * @return array
   *   Legacy-shaped result consumed by savePushResponse():
   *   ['Invoices' => ['Invoice' => snapshot]] or ['ValidationErrors' => [...]].
   *
   * @throws \XeroAPI\XeroPHP\ApiException
   * @throws \CRM_Core_Exception
   */
  private function pushViaApi(array $mapped): array {
    $invoice = new Invoice();
    $invoice->setType($mapped['Type'] ?? 'ACCREC');
    if (!empty($mapped['InvoiceID'])) {
      $this->assertValidXeroGuid((string) $mapped['InvoiceID'], 'Xero invoice reference (InvoiceID)');
      $invoice->setInvoiceId($mapped['InvoiceID']);
    }
    $contactRef = $this->buildSdkContactRef($mapped);
    if ($contactRef !== NULL) {
      $invoice->setContact($contactRef);
    }
    if (!empty($mapped['Date'])) {
      $invoice->setDate($mapped['Date']);
    }
    if (!empty($mapped['DueDate'])) {
      $invoice->setDueDate($mapped['DueDate']);
    }
    if (!empty($mapped['Status'])) {
      $invoice->setStatus($mapped['Status']);
    }
    if (!empty($mapped['InvoiceNumber'])) {
      $invoice->setInvoiceNumber(mb_substr((string) $mapped['InvoiceNumber'], 0, 255));
    }
    if (!empty($mapped['CurrencyCode'])) {
      $invoice->setCurrencyCode($mapped['CurrencyCode']);
    }
    if (isset($mapped['Reference'])) {
      $invoice->setReference(mb_substr((string) $mapped['Reference'], 0, 255));
    }
    if (!empty($mapped['LineAmountTypes'])) {
      $invoice->setLineAmountTypes($mapped['LineAmountTypes']);
    }
    $invoice->setLineItems($this->buildSdkLineItems($mapped));

    $collection = new Invoices();
    $collection->setInvoices([$invoice]);

    // summarize_errors = FALSE: per-invoice validation errors come back on
    // the invoice object instead of a blanket HTTP 400.
    $response = $this->getAccountingApiInstance()->updateOrCreateInvoices(
      $this->getTenantID(),
      $collection,
      FALSE,
      NULL,
      $this->generateIdempotencyKey('invoice-' . ($mapped['InvoiceNumber'] ?? '0'), $mapped)
    );

    $returned = $response->getInvoices()[0] ?? NULL;
    if ($returned === NULL) {
      throw new CRM_Core_Exception('CWS CiviXero Plus: Xero returned no invoice from updateOrCreateInvoices');
    }
    $validationErrors = $this->extractValidationErrors($returned);
    if ($validationErrors !== []) {
      return ['ValidationErrors' => $validationErrors];
    }

    $snapshot = json_decode((string) $returned, TRUE) ?: [];
    $updated = $returned->getUpdatedDateUtcAsDate();
    $snapshot['InvoiceID'] = $returned->getInvoiceId();
    $snapshot['UpdatedDateUTC'] = $updated ? $updated->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $snapshot['Status'] = $returned->getStatus();
    return ['Invoices' => ['Invoice' => $snapshot]];
  }

}
