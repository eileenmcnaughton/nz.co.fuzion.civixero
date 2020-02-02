<?php

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
  protected $xero_entity = 'BankTransaction';

  /**
   * Push record to Xero.
   *
   * @param array $accountsInvoice
   *
   * @param int $connector_id
   *   ID of the connector (0 if nz.co.fuzion.connectors not installed.
   *
   * @return array
   */
  protected function pushToXero($accountsInvoice, $connector_id) {
    if ($accountsInvoice === FALSE) {
      return FALSE;
    }
    $result = $this->getSingleton($connector_id)->BankTransactions($accountsInvoice);
    return $result;
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
   * @param int $accountsID
   *
   * @return array|bool
   *   BankTransaction Object/ array as expected by accounts package.
   */
  protected function mapToAccounts($invoiceData, $accountsID) {
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
        "Description" => $lineItem['display_name'] . ' ' . str_replace(['&nbsp;'], ' ', $lineItem['label']),
        "Quantity" => $lineItem['qty'],
        "UnitAmount" => $lineItem['unit_price'],
        "AccountCode" => !empty($lineItem['accounting_code']) ? $lineItem['accounting_code'] : $this->getDefaultAccountCode(),
      ];
    }

    $new_invoice = [
      "Type" => "RECEIVE",
      "Contact" => [
        "ContactNumber" => $invoiceData['contact_id'],
      ],
      "Date" => substr($invoiceData['receive_date'], 0, 10),
      "Status" => "AUTHORISED",
      "CurrencyCode" => CRM_Core_Config::singleton()->defaultCurrency,
      "Reference" => $invoiceData['display_name'] . ' ' . $invoiceData['contribution_source'],
      "LineAmountTypes" => "Inclusive",
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
    if ($accountsID) {
      $new_invoice['BankTransactionID'] = $accountsID;
    }

    $proceed = TRUE;
    CRM_Accountsync_Hook::accountPushAlterMapped('bank_transaction', $invoiceData, $proceed, $new_invoice);
    if (!$proceed) {
      return FALSE;
    }

    $this->validatePrerequisites($new_invoice);
    $new_invoice = [
      $new_invoice,
    ];
    return $new_invoice;
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
  protected function isSplitTransactions() {
    return TRUE;
  }

  /**
   * Get a list of responses indicating the transaction cannot be updated.
   *
   * @return array
   */
  protected function getNotUpdateCandidateResponses() {
    return [
      'This Bank Transaction cannot be edited as it has been reconciled with a Bank Statement.',
    ];
  }

}
