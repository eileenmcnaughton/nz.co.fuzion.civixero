<?php

use Civi\BankTransactionMappingTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for CRM_Civixero_BankTransaction's mapToAccounts().
 *
 * BankTransaction largely inherits Invoice's push()/savePushResponse() (see
 * InvoiceMappingTest/InvoiceResponseHandlingTest for those), but overrides
 * mapToAccounts() with a different Xero shape (RECEIVE bank transaction
 * rather than an ACCREC/ACCPAY invoice) and getNotUpdateCandidateResponses().
 *
 * @group headless
 */
class BankTransactionMappingTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    parent::setUp();
  }

  private function getBankTransaction(): BankTransactionMappingTestable {
    return new BankTransactionMappingTestable([]);
  }

  private function getBasicInvoiceData(): array {
    return [
      'id' => 789,
      'contact_id' => 55,
      'receive_date' => '2024-03-15 10:00:00',
      'display_name' => 'Jane Doe',
      'contribution_source' => 'Donation',
      'payment_instrument_accounting_code' => '090',
      'line_items' => [
        [
          'display_name' => 'Jane Doe',
          'label' => 'General Donation',
          'qty' => 1,
          'unit_price' => 25,
          'accounting_code' => '400',
        ],
      ],
    ];
  }

  public function testMapToAccountsBasicFields(): void {
    $result = $this->getBankTransaction()->callMapToAccounts($this->getBasicInvoiceData(), NULL);

    $this->assertCount(1, $result);
    $bankTransaction = $result[0];
    $this->assertEquals('RECEIVE', $bankTransaction['Type']);
    // Note: unlike Invoice's mapToAccounts(), the contact is referenced by
    // ContactNumber (the CiviCRM contact ID), not a Xero ContactID.
    $this->assertEquals(['ContactNumber' => 55], $bankTransaction['Contact']);
    $this->assertEquals('2024-03-15', $bankTransaction['Date']);
    $this->assertEquals('AUTHORISED', $bankTransaction['Status']);
    $this->assertEquals('Jane Doe Donation', $bankTransaction['Reference']);
    $this->assertEquals('Inclusive', $bankTransaction['LineAmountTypes']);
    $this->assertEquals(['Code' => '090'], $bankTransaction['BankAccount']);
    $this->assertArrayNotHasKey('BankTransactionID', $bankTransaction);

    $lineItem = $bankTransaction['LineItems']['LineItem'][0];
    $this->assertEquals('Jane Doe General Donation', $lineItem['Description']);
    $this->assertEquals(1, $lineItem['Quantity']);
    $this->assertEquals(25, $lineItem['UnitAmount']);
    $this->assertEquals('400', $lineItem['AccountCode']);
  }

  public function testMapToAccountsIncludesBankTransactionIDWhenUUIDProvided(): void {
    $result = $this->getBankTransaction()->callMapToAccounts($this->getBasicInvoiceData(), 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result[0]['BankTransactionID']);
  }

  public function testMapToAccountsUsesDefaultAccountCodeWhenLineItemHasNone(): void {
    $invoiceData = $this->getBasicInvoiceData();
    unset($invoiceData['line_items'][0]['accounting_code']);
    $result = $this->getBankTransaction()->callMapToAccounts($invoiceData, NULL);
    // Default is the xero_default_revenue_account setting, which defaults to 200.
    $this->assertEquals('200', (string) $result[0]['LineItems']['LineItem'][0]['AccountCode']);
  }

  public function testGetNotUpdateCandidateResponsesReconciledMessage(): void {
    $responses = $this->getBankTransaction()->callGetNotUpdateCandidateResponses();
    $this->assertContains(
      'This Bank Transaction cannot be edited as it has been reconciled with a Bank Statement.',
      $responses
    );
  }

  public function testIsSplitTransactionsIsTrueForBankTransactions(): void {
    // Bank transactions (unlike Invoices) support splitting line items across
    // different connector accounts - see CRM_Civixero_Invoice::isSplitTransactions().
    $reflection = new ReflectionMethod('CRM_Civixero_BankTransaction', 'isSplitTransactions');
    $reflection->setAccessible(TRUE);
    $this->assertTrue($reflection->invoke($this->getBankTransaction()));
  }

}
