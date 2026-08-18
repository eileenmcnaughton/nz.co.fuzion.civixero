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
   * Error codes for CRM_Core_Exceptions thrown by getMappedAccountInvoice()/mapToAccounts()
   * that represent a genuine no-op (nothing to push, nothing wrong) rather than a real
   * error - see push()'s handling of these below.
   */
  private const ERROR_CODE_ALREADY_COMPLETED_IN_XERO = 'already_completed_in_xero';

  private const ERROR_CODE_CANCELLED = 'invoice_cancelled';

  private const ERROR_CODE_HOOK_SKIPPED = 'hook_skipped';

  private const RESOLVED_ERROR_CODES = [
    self::ERROR_CODE_ALREADY_COMPLETED_IN_XERO,
    self::ERROR_CODE_CANCELLED,
    self::ERROR_CODE_HOOK_SKIPPED,
  ];

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
    catch (\XeroAPI\XeroPHP\ApiException $e) {
      $this->throwIfRateLimited($e);
      \Civi::log(E::SHORT_NAME)->error('Exception when calling AccountingApi->getInvoices: ' . $e->getMessage());
      throw $e;
    }
    catch (\Exception $e) {
      \Civi::log(E::SHORT_NAME)->error('Exception when calling AccountingApi->getInvoices: ' . $e->getMessage());
      throw $e;
    }
    return $invoices ?? [];
  }

  /**
   * Pull contacts from Xero and store them into civicrm_account_contact.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *
   * Errors on one page (a save failure, a transient API error) do not stop
   * later pages from being attempted - only an authentication failure or
   * Xero rate-limiting stops the run early, since every subsequent page
   * would fail identically. If any page had errors, an aggregate
   * CRM_Core_Exception is thrown once all pages have been attempted, so the
   * job shows as failed even though it made partial progress.
   *
   * @param array $params
   *
   * @throws CRM_Core_Exception
   */
  public function pullUsingApi4(array $params): void {
    $pageSize = 100;
    // Ignore start/modified date if we specified IDs.
    // Note: this deliberately checks 'xero_invoice_number', not
    // 'invoice_number' (the actual param name) - a pre-existing quirk in the
    // original condition, preserved as-is rather than fixed here.
    $ignoreDate = !empty($params['xero_contact_id']) || !empty($params['xero_invoice_id']) || !empty($params['xero_invoice_number']);

    $this->runResilientPagingPull(
      fn(int $page) => $this->pullFromXero(
        FALSE,
        FALSE,
        '',
        $page,
        $pageSize,
        $ignoreDate ? '-1 week' : $params['start_date'],
        $params['xero_invoice_id'] ?? '',
        $params['invoice_number'] ?? '',
        $params['xero_contact_id'] ?? ''
      ),
      fn(array $invoices) => $this->processPull($invoices, $params['connector_id'] ?? 0, $params['create_contributions_in_civicrm'] ?? FALSE),
      'Invoice'
    );
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

      $matchedByInvoiceNumber = FALSE;
      if (empty($accountInvoice) || empty($accountInvoice['contribution_id'])) {
        $matchResult = $this->getContributionIDFromInvoiceNumberMatch($xeroInvoice, $connectorID);
        if ($matchResult) {
          // A reliable match takes precedence over the prefix-derived one.
          $matchedByInvoiceNumber = TRUE;
          $accountInvoiceParams['contribution_id'] = $matchResult;
        }
        elseif ($matchResult === FALSE) {
          unset($accountInvoiceParams['contribution_id']);
        }
        // NULL: no candidate (or setting disabled). For an existing
        // AccountInvoice row this leaves any prefix-derived ID alone; for a
        // brand new row it's moot, as the create branch below unsets it
        // anyway unless $matchedByInvoiceNumber is TRUE.
      }
      try {
        if (empty($accountInvoice)) {
          // Invoice is in Xero but (accounts_invoice_id) does not exist in account_invoice table
          // Note $contributionId will be invalid if it was generated at Xero and did not exist in CiviCRM because it is
          //   derived from Xero invoice ID without prefix.
          // So we can't use contribution ID - remove it, and then we'll record a new entry in account_invoice with Xero invoice.
          // This could be manually reconciled by adding a contribution ID.
          // The exception is an exact match on the contribution's invoice_number
          // (guarded by uniqueness/amount checks) which is reliable enough to link.
          if (!$matchedByInvoiceNumber) {
            unset($accountInvoiceParams['contribution_id']);
          }
          $pendingAccountInvoice = NULL;
          if ($matchedByInvoiceNumber) {
            $pendingAccountInvoice = AccountInvoice::get(FALSE)
              ->addWhere('plugin', '=', $this->_plugin)
              ->addWhere('connector_id', '=', $connectorID)
              ->addWhere('contribution_id', '=', $accountInvoiceParams['contribution_id'])
              ->addWhere('accounts_invoice_id', 'IS NULL')
              ->execute()
              ->first();
          }
          if (!empty($pendingAccountInvoice)) {
            $adoptParams = $accountInvoiceParams;
            unset($adoptParams['accounts_needs_update']);
            $newAccountInvoice = AccountInvoice::update(FALSE)
              ->setValues($adoptParams)
              ->addWhere('id', '=', $pendingAccountInvoice['id'])
              ->execute()
              ->first();
          }
          else {
            // Create a new AccountInvoice record
            $newAccountInvoice = AccountInvoice::create(FALSE)
              ->setValues($accountInvoiceParams)
              ->execute()
              ->first();
          }
          $ids[] = $newAccountInvoice['id'];
        }
        else {
          // Update existing AccountInvoice record
          $modifiedFieldKeys = [
            'accounts_modified_date',
            'accounts_status_id',
            'accounts_needs_update',
          ];
          // Every time we do an "update" last_sync_date is updated which triggers an entry in log_civicrm_account_contact.
          // So check if anything actually changed before updating.
          $somethingChanged = FALSE;
          foreach ($modifiedFieldKeys as $key) {
            if ($accountInvoiceParams[$key] !== $accountInvoice[$key]) {
              $somethingChanged = TRUE;
              break;
            }
          }
          // A reliable invoice-number match that back-fills a missing
          // contribution link is a change in its own right - otherwise
          // existing unlinked rows are never reconnected unless a tracked
          // field happens to have changed in Xero since the last pull.
          if (!$somethingChanged && $matchedByInvoiceNumber && empty($accountInvoice['contribution_id']) && isset($accountInvoiceParams['contribution_id'])) {
            $somethingChanged = TRUE;
          }
          if ($somethingChanged) {
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
          if (!$this->isContributionEligibleForPush($accountInvoice)) {
            // The accountsync queue-time settings no longer allow this
            // contribution accountsync will re-queue it if a later edit makes
            // it eligible again.
            AccountInvoice::update(FALSE)
              ->addWhere('id', '=', $accountInvoice['id'])
              ->addValue('accounts_needs_update', FALSE)
              ->execute();
            continue;
          }
          $mappedAccountInvoice = $this->getMappedAccountInvoice($accountInvoice);
          if ($mappedAccountInvoice === NULL) {
            // Contact not yet synced to Xero — leave accounts_needs_update set and try again next run.
            continue;
          }
        }
        catch (CRM_Core_Exception $e) {
          // getMappedAccountInvoice()/mapToAccounts() throw some exceptions (already
          // completed in Xero, cancelled, vetoed by the accountPushAlterMapped hook) that
          // are a genuine no-op rather than a real error - mark those resolved so they
          // don't show up in error reports, and don't count them as failures below.
          $isErrorResolved = in_array($e->getErrorCode(), self::RESOLVED_ERROR_CODES, TRUE);
          // We need to set an error so that they are not selected for push next time otherwise we'll keep trying to push the same ones
          AccountInvoice::update(FALSE)
            ->addWhere('id', '=', $accountInvoice['id'])
            ->addValue('error_data', json_encode([
              'error' => $e->getMessage(),
              'error_data' => $accountInvoice['error_data'],
            ]))
            ->addValue('is_error_resolved', $isErrorResolved)
            ->addValue('accounts_needs_update', FALSE)
            ->addValue('accounts_data', json_encode($accountInvoice))
            ->execute();
          if (!$isErrorResolved) {
            $errors[] = $e->getMessage();
          }
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
   * Re-check the accountsync queue-time settings at push time.
   *
   * @param array $accountInvoice
   *   The AccountInvoice record queued for push.
   *
   * @return bool
   *   TRUE if the push should proceed.
   *
   * @throws \CRM_Core_Exception
   */
  protected function isContributionEligibleForPush(array $accountInvoice): bool {
    $contributionID = $accountInvoice['contribution_id'] ?? NULL;
    if (!$contributionID) {
      // Nothing to check against - let the existing flow handle it.
      return TRUE;
    }
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id', 'receive_date')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();
    if (empty($contribution)) {
      // Deleted contribution - the existing flow records this as an error.
      return TRUE;
    }

    // 1. Push Contribution Status.
    // No !empty($enabledStatuses) guard here: the queue-time check
    // (accountsync_civicrm_post()) treats an empty setting as "nothing is
    // eligible" (!in_array($status, []) is always TRUE), and this re-check
    // needs to agree with that or a cleared setting would silently stop
    // blocking new pushes after already-queued rows are re-validated here.
    $enabledStatuses = (array) \Civi::settings()->get('account_sync_push_contribution_status');
    if (!in_array($contribution['contribution_status_id'], $enabledStatuses)) {
      \Civi::log('civixero')->info('Invoice push: skipping contribution {contributionID} - status {statusID} is not an enabled push status.', [
        'contributionID' => $contributionID,
        'statusID' => $contribution['contribution_status_id'],
      ]);
      return FALSE;
    }

    // 2. Day zero for contributions.
    $dayZero = \Civi::settings()->get('account_sync_contribution_day_zero');
    if (!empty($dayZero) && !empty($contribution['receive_date'])) {
      try {
        if (new DateTime($contribution['receive_date']) < new DateTime($dayZero)) {
          \Civi::log('civixero')->info('Invoice push: skipping contribution {contributionID} - received {receiveDate}, before day zero {dayZero}.', [
            'contributionID' => $contributionID,
            'receiveDate' => $contribution['receive_date'],
            'dayZero' => $dayZero,
          ]);
          return FALSE;
        }
      }
      catch (Exception $e) {
        // Don't block the push on an unparseable date - but do surface it,
        // since a silently ignored day zero is exactly the bug this check
        // exists to prevent.
        \Civi::log('civixero')->warning('Invoice push: could not compare receive_date {receiveDate} with day zero {dayZero}: {message}', [
          'receiveDate' => $contribution['receive_date'],
          'dayZero' => $dayZero,
          'message' => $e->getMessage(),
        ]);
      }
    }

    // 3. Skip invoice creation by payment processor.
    $skipProcessorIDs = array_filter((array) \Civi::settings()->get('account_sync_skip_inv_by_pymt_processor'));
    if (!empty($skipProcessorIDs)) {
      $processorTrxn = \Civi\Api4\EntityFinancialTrxn::get(FALSE)
        ->addSelect('financial_trxn_id.payment_processor_id')
        ->addWhere('entity_table', '=', 'civicrm_contribution')
        ->addWhere('entity_id', '=', $contributionID)
        ->addWhere('financial_trxn_id.payment_processor_id', 'IS NOT NULL')
        ->addOrderBy('financial_trxn_id', 'DESC')
        ->setLimit(1)
        ->execute()
        ->first();
      $paymentProcessorID = $processorTrxn['financial_trxn_id.payment_processor_id'] ?? NULL;
      if ($paymentProcessorID && in_array($paymentProcessorID, $skipProcessorIDs)) {
        \Civi::log('civixero')->info('Invoice push: skipping contribution {contributionID} - paid via payment processor {processorID} which is configured to be skipped.', [
          'contributionID' => $contributionID,
          'processorID' => $paymentProcessorID,
        ]);
        return FALSE;
      }
    }

    return TRUE;
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
   * @return array
   *   Invoice array as expected by accounts package
   * @throws \CRM_Core_Exception
   */
  protected function mapToAccounts(array $invoiceData, ?string $xeroInvoiceUUID): array {
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

    $new_invoice = [
      'Type' => ($total_amount > 0) ? 'ACCREC' : 'ACCPAY',
      'Contact' => [
        'ContactID' => $invoiceData['accounts_contact_id'],
      ],
      'Date' => substr($invoiceData['receive_date'], 0, 10),
      'DueDate' => substr($invoiceData['receive_date'], 0, 10),
      'Status' => $status,
      'InvoiceNumber' => $this->getInvoiceNumber((int) ($invoiceData['id'] ?? NULL), $invoiceData['invoice_number'] ?? NULL),
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
      throw new CRM_Core_Exception('Ignored via accountPushAlterMapped hook', self::ERROR_CODE_HOOK_SKIPPED);
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
    return [
      'Invoice' => [
        'InvoiceID' => $xeroInvoiceUUID,
        'InvoiceNumber' => $this->getInvoiceNumber($contributionID),
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
   * Get the invoice number to send to Xero for a contribution.
   *
   * @param int $contributionID
   * @param string|null $contributionInvoiceNumber
   *   The contribution's invoice_number if already known by the caller.
   *   Pass NULL to have it looked up when required.
   *
   * @return string
   */
  protected function getInvoiceNumber(int $contributionID, ?string $contributionInvoiceNumber = NULL): string {
    if ($this->settings->get('xero_use_contribution_invoice_number')) {
      if ($contributionInvoiceNumber === NULL) {
        try {
          $contributionInvoiceNumber = (string) (Contribution::get(FALSE)
            ->addSelect('invoice_number')
            ->addWhere('id', '=', $contributionID)
            ->execute()
            ->first()['invoice_number'] ?? '');
        }
        catch (Exception $e) {
          \Civi::log('civixero')->warning('getInvoiceNumber: could not load invoice_number for contribution ' . $contributionID . ': ' . $e->getMessage());
          $contributionInvoiceNumber = '';
        }
      }
      // Explicit check rather than empty(): the string '0' is a valid
      // (if unlikely) invoice number and empty('0') is TRUE.
      if ($contributionInvoiceNumber !== NULL && $contributionInvoiceNumber !== '') {
        return (string) $contributionInvoiceNumber;
      }
    }
    $prefix = $this->settings->get('xero_invoice_number_prefix') ?: '';

    return $prefix . $contributionID;
  }

  /**
   * Try to match a pulled Xero invoice to a contribution by invoice number.
   *
   * @param array $xeroInvoice
   *   Invoice data pulled from Xero (needs invoice_number, invoice_id, total).
   * @param int $connectorID
   *   ID of the connector (0 if nz.co.fuzion.connectors is not installed).
   *
   * @return int|false|null
   *   - int: the matched contribution ID.
   *   - NULL: no candidate (or setting disabled) - the caller may fall back
   *     to other matching strategies.
   *   - FALSE: a candidate was found but refused as unsafe (duplicate
   *     invoice_number, linked to a different Xero invoice, or total
   *     mismatch) - the caller must NOT fall back to weaker matching.
   */
  protected function getContributionIDFromInvoiceNumberMatch(array $xeroInvoice, int $connectorID) {
    $xeroInvoiceNumber = $xeroInvoice['invoice_number'] ?? NULL;
    // Explicit check rather than empty(): the string '0' is a valid
    // (if unlikely) invoice number and empty('0') is TRUE.
    if (!$this->getSetting('xero_use_contribution_invoice_number') || $xeroInvoiceNumber === NULL || $xeroInvoiceNumber === '') {
      return NULL;
    }

    $contributions = Contribution::get(FALSE)
      ->addSelect('id', 'total_amount')
      ->addWhere('invoice_number', '=', $xeroInvoice['invoice_number'])
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('is_template', '=', FALSE)
      ->setLimit(2)
      ->execute();
    if (count($contributions) === 0) {
      // No candidate - the caller may fall back to other matching strategies.
      return NULL;
    }
    if (count($contributions) > 1) {
      \Civi::log('civixero')->warning('Invoice pull: multiple contributions share invoice_number {invoiceNumber} - not linking Xero invoice {xeroInvoiceID}.', [
        'invoiceNumber' => $xeroInvoice['invoice_number'],
        'xeroInvoiceID' => $xeroInvoice['invoice_id'] ?? '',
      ]);
      return FALSE;
    }
    $contribution = $contributions->first();

    // Don't steal a contribution that is already linked to a different Xero invoice.
    $existingLink = AccountInvoice::get(FALSE)
      ->addSelect('id', 'accounts_invoice_id')
      ->addWhere('plugin', '=', $this->_plugin)
      ->addWhere('connector_id', '=', $connectorID)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->execute()
      ->first();
    if (!empty($existingLink['accounts_invoice_id']) && $existingLink['accounts_invoice_id'] !== ($xeroInvoice['invoice_id'] ?? '')) {
      \Civi::log('civixero')->warning('Invoice pull: contribution {contributionID} (invoice_number {invoiceNumber}) is already linked to Xero invoice {existing} - not linking Xero invoice {xeroInvoiceID}.', [
        'contributionID' => $contribution['id'],
        'invoiceNumber' => $xeroInvoice['invoice_number'],
        'existing' => $existingLink['accounts_invoice_id'],
        'xeroInvoiceID' => $xeroInvoice['invoice_id'] ?? '',
      ]);
      return FALSE;
    }

    // Sanity check: totals should match.
    if (isset($xeroInvoice['total']) && isset($contribution['total_amount'])
      && abs(abs((float) $xeroInvoice['total']) - abs((float) $contribution['total_amount'])) > 0.011) {
      \Civi::log('civixero')->warning('Invoice pull: Xero invoice {xeroInvoiceID} total {xeroTotal} does not match contribution {contributionID} total {civiTotal} - not linking despite invoice_number match.', [
        'xeroInvoiceID' => $xeroInvoice['invoice_id'] ?? '',
        'xeroTotal' => $xeroInvoice['total'],
        'contributionID' => $contribution['id'],
        'civiTotal' => $contribution['total_amount'],
      ]);
      return FALSE;
    }

    return (int) $contribution['id'];
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
      ->addSelect('*', 'accounts_status_id:name')
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
   * @return array|null
   *   Invoice payload for Xero, or NULL to defer until later. Throws (rather than
   *   returning FALSE) to skip permanently - see RESOLVED_ERROR_CODES.
   * @throws \CRM_Core_Exception
   */
  protected function getMappedAccountInvoice(array $record): ?array {
    if ($record['accounts_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Accountsync_BAO_AccountInvoice', 'accounts_status_id', 'cancelled')) {
      throw new CRM_Core_Exception('AccountInvoice is cancelled', self::ERROR_CODE_CANCELLED);
    }

    $xeroInvoiceUUID = $record['accounts_invoice_id'] ?? NULL;
    $contributionID = $record['contribution_id'];
    if (empty($contributionID)) {
      // This AccountInvoice was created from a Xero invoice that has no matching
      // CiviCRM contribution yet (create-contribution disabled, or not yet matched
      // by the pull job) - there is nothing to push.
      throw new CRM_Core_Exception('Can not push AccountInvoice with no Contribution ID');
    }

    $civiCRMInvoice = civicrm_api3('AccountInvoice', 'getderived', [
      'id' => $contributionID,
    ])['values'][$contributionID] ?? [];

    $contributionStatusName = CRM_Core_PseudoConstant::getName('CRM_Contribute_DAO_Contribution', 'contribution_status_id', $civiCRMInvoice['contribution_status_id']);
    $cancelledStatuses = ['Failed', 'Cancelled'];

    if (empty($civiCRMInvoice) || in_array($contributionStatusName, $cancelledStatuses)) {
      return $this->mapCancelled($contributionID, $xeroInvoiceUUID);
    }

    if ($xeroInvoiceUUID && $record['accounts_status_id:name'] === 'completed') {
      // Already Completed (Paid) in Xero - pushing would fail because mapToAccounts()
      // always pushes using the default invoice status setting, which is never Completed
      // (e.g. "the status SUBMITTED cannot be applied ... it has payments allocated to it").
      throw new CRM_Core_Exception('AccountInvoice already completed in Xero', self::ERROR_CODE_ALREADY_COMPLETED_IN_XERO);
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
    catch (\XeroAPI\XeroPHP\ApiException $e) {
      $this->throwIfRateLimited($e);
      throw new CRM_Core_Exception(
        'Synchronization error ' . $e->getMessage(),
        'xero_' . $e->getCode(),
        ['response' => $e->getResponseBody()]
      );
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
  protected function pushViaApi(array $mapped): array {
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
