<?php

use XeroAPI\XeroPHP\Models\Accounting\Account;
use XeroAPI\XeroPHP\Models\Accounting\BankTransaction;
use XeroAPI\XeroPHP\Models\Accounting\BankTransactions;

/**
 * Class CRM_Civixero_BankTransaction.
 *
 * This class is intended to be used as an alternative to invoice push.
 *
 * It largely inherits the invoice class but creates Bank transaction
 * (payment receipt) records instead of CiviCRM.
 *
 * To choose to push transactions as bank receipts rather than invoices
 * you need to configure the Banktransaction.Push api as a scheduled job
 * rather than an invoice push.
 *
 * This is envisaged as a one way job and a 'pull' is not anticipated.
 *
 * The two actions differ in which Xero entity they map to and the field
 * mappings but are otherwise the same.
 */
class CRM_Civixero_BankTransaction extends CRM_Civixero_Invoice {

  /**
   * Name in Xero of entity being pushed.
   *
   * @var string
   */
  protected string $xero_entity = 'BankTransaction';

  /**
   * Map civicrm Array to Accounts package field names.
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
   *   BankTransaction array as expected by accounts package.
   */
  protected function mapToAccounts(array $invoiceData, ?string $xeroInvoiceUUID): array {
    $lineItems = [];

    foreach ($invoiceData['line_items'] as $lineItem) {
      if ($this->connector_id != 0
        && $this->getAccountsContact()
        && $lineItem['accounts_contact_id'] != $this->getAccountsContact()) {
        // We have configured the connect to be account specific and we are
        // dealing with an account not related to this connector.
        // This can result (intentionally) in some line items being pushed
        // to one connector and some to another. To avoid this don't put a
        // contact_id on the connector account.
        continue;
      }
      $lineItems[] = [
        'Description' => $lineItem['display_name'] . ' ' . str_replace(['&nbsp;'], ' ', $lineItem['label']),
        'Quantity' => $lineItem['qty'],
        'UnitAmount' => $lineItem['unit_price'],
        'AccountCode' => !empty($lineItem['accounting_code']) ? $lineItem['accounting_code'] : $this->getDefaultAccountCode(),
      ];
    }

    $new_invoice = [
      'Type' => 'RECEIVE',
      'Contact' => [
        'ContactNumber' => $invoiceData['contact_id'],
      ],
      'Date' => substr($invoiceData['receive_date'], 0, 10),
      'Status' => 'AUTHORISED',
      'CurrencyCode' => CRM_Core_Config::singleton()->defaultCurrency,
      'Reference' => $invoiceData['display_name'] . ' ' . $invoiceData['contribution_source'],
      'LineAmountTypes' => 'Inclusive',
      'LineItems' => ['LineItem' => $lineItems],
      'BankAccount' => [
        'Code' => $invoiceData['payment_instrument_accounting_code'],
      ],
      'Url' => CRM_Utils_System::url(
        'civicrm/contact/view/contribution',
        ['reset' => 1, 'id' => $invoiceData['id'], 'action' => 'view'],
        TRUE
      ),
    ];
    if ($xeroInvoiceUUID) {
      $new_invoice['BankTransactionID'] = $xeroInvoiceUUID;
    }

    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('bank_transaction', $invoiceData, $proceed, $new_invoice);
    if (!$proceed) {
      throw new CRM_Core_Exception('Ignored via accountPushAlterMapped hook');
    }

    $this->validatePrerequisites($new_invoice);
    return [$new_invoice];
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
    return TRUE;
  }

  /**
   * Get a list of responses indicating the transaction cannot be updated.
   *
   * @return array
   */
  protected function getNotUpdateCandidateResponses(): array {
    return [
      'This Bank Transaction cannot be edited as it has been reconciled with a Bank Statement.',
    ];
  }


  /**
   * Push one bank transaction via AccountingApi::updateOrCreateBankTransactions.
   *
   * @return array
   *   Legacy-shaped result consumed by savePushResponse():
   *   ['BankTransactions' => ['BankTransaction' => snapshot]] or
   *   ['ValidationErrors' => [...]].
   *
   * @throws \XeroAPI\XeroPHP\ApiException
   * @throws \CRM_Core_Exception
   */
  private function pushViaApi(array $mapped): array {
    $bankTransaction = new BankTransaction();
    $bankTransaction->setType($mapped['Type'] ?? 'RECEIVE');
    if (!empty($mapped['BankTransactionID'])) {
      $this->assertValidXeroGuid((string) $mapped['BankTransactionID'], 'Xero bank transaction reference (BankTransactionID)');
      $bankTransaction->setBankTransactionId($mapped['BankTransactionID']);
    }
    $contactRef = $this->buildSdkContactRef($mapped);
    if ($contactRef !== NULL) {
      $bankTransaction->setContact($contactRef);
    }
    if (!empty($mapped['Date'])) {
      $bankTransaction->setDate($mapped['Date']);
    }
    if (!empty($mapped['Status'])) {
      $bankTransaction->setStatus($mapped['Status']);
    }
    if (!empty($mapped['CurrencyCode'])) {
      $bankTransaction->setCurrencyCode($mapped['CurrencyCode']);
    }
    if (isset($mapped['Reference'])) {
      $bankTransaction->setReference(mb_substr((string) $mapped['Reference'], 0, 255));
    }
    if (!empty($mapped['Url'])) {
      $bankTransaction->setUrl($mapped['Url']);
    }
    if (!empty($mapped['BankAccount']['Code'])) {
      $bankAccount = new Account();
      $bankAccount->setCode((string) $mapped['BankAccount']['Code']);
      $bankTransaction->setBankAccount($bankAccount);
    }
    $bankTransaction->setLineItems($this->buildSdkLineItems($mapped));

    $collection = new BankTransactions();
    $collection->setBankTransactions([$bankTransaction]);

    $response = $this->getAccountingApiInstance()->updateOrCreateBankTransactions(
      $this->getTenantID(),
      $collection,
      FALSE,
      NULL,
      $this->generateIdempotencyKey('banktransaction-' . ($mapped['Reference'] ?? '0'), $mapped)
    );

    $returned = $response->getBankTransactions()[0] ?? NULL;
    if ($returned === NULL) {
      throw new CRM_Core_Exception('Xero returned no bank transaction from updateOrCreateBankTransactions');
    }
    $validationErrors = $this->extractValidationErrors($returned);
    if ($validationErrors !== []) {
      return ['ValidationErrors' => $validationErrors];
    }

    $snapshot = json_decode((string) $returned, TRUE) ?: [];
    $updated = $returned->getUpdatedDateUtcAsDate();
    $snapshot['BankTransactionID'] = $returned->getBankTransactionId();
    $snapshot['UpdatedDateUTC'] = $updated ? $updated->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $snapshot['Status'] = $returned->getStatus();
    return ['BankTransactions' => ['BankTransaction' => $snapshot]];
  }

}
