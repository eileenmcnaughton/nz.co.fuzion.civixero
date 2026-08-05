<?php

use Civi\InvoiceMappingTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for CRM_Civixero_Invoice's mapToAccounts()/mapCancelled().
 *
 * These pin down the array shape produced today (pre-SDK-migration) from a
 * CiviCRM-shaped invoice/contribution array. Nothing here touches the
 * network - mapToAccounts()/mapCancelled() are pure transforms over settings
 * + input array. The goal is to have something that keeps passing whichever
 * SDK pushToXero() ends up using, since these methods aren't touched by the
 * SDK migration - only the code that consumes their output is.
 *
 * @group headless
 */
class InvoiceMappingTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    // Pin down the invoice-due-date-from-settings branch so it can't leak
    // in from whatever this site's Contribute settings happen to be.
    Civi::settings()->set('invoice_due_date', 0);
    Civi::settings()->set('invoice_due_date_period', 'select');
    parent::setUp();
  }

  private function getInvoice(): InvoiceMappingTestable {
    return new InvoiceMappingTestable([]);
  }

  private function getBasicInvoiceData(): array {
    return [
      'id' => 123,
      'accounts_contact_id' => '11111111-1111-1111-1111-111111111111',
      'receive_date' => '2024-03-15 10:00:00',
      'currency' => 'NZD',
      'display_name' => 'Jane Doe',
      'contribution_source' => 'Event registration',
      'line_items' => [
        [
          'display_name' => 'Jane Doe',
          'label' => 'General Admission',
          'qty' => 1,
          'unit_price' => 50,
          'accounting_code' => '400',
        ],
      ],
    ];
  }

  public function testMapToAccountsBasicFields(): void {
    $result = $this->getInvoice()->callMapToAccounts($this->getBasicInvoiceData(), NULL);

    $this->assertCount(1, $result);
    $invoice = $result[0];
    $this->assertEquals('ACCREC', $invoice['Type']);
    $this->assertEquals(['ContactID' => '11111111-1111-1111-1111-111111111111'], $invoice['Contact']);
    $this->assertEquals('2024-03-15', $invoice['Date']);
    $this->assertEquals('2024-03-15', $invoice['DueDate']);
    $this->assertEquals('SUBMITTED', $invoice['Status']);
    $this->assertEquals('CIVI123', $invoice['InvoiceNumber']);
    $this->assertEquals('NZD', $invoice['CurrencyCode']);
    $this->assertEquals('Jane Doe Event registration', $invoice['Reference']);
    $this->assertEquals('Inclusive', $invoice['LineAmountTypes']);
    $this->assertArrayNotHasKey('InvoiceID', $invoice);

    $lineItem = $invoice['LineItems']['LineItem'][0];
    $this->assertEquals('Jane Doe General Admission', $lineItem['Description']);
    $this->assertEquals(1, $lineItem['Quantity']);
    $this->assertEquals(50, $lineItem['UnitAmount']);
    $this->assertEquals('400', $lineItem['AccountCode']);
  }

  public function testMapToAccountsIncludesInvoiceIDWhenUUIDProvided(): void {
    $result = $this->getInvoice()->callMapToAccounts($this->getBasicInvoiceData(), 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result[0]['InvoiceID']);
  }

  public function testMapToAccountsUsesDefaultAccountCodeWhenLineItemHasNone(): void {
    $invoiceData = $this->getBasicInvoiceData();
    unset($invoiceData['line_items'][0]['accounting_code']);
    $result = $this->getInvoice()->callMapToAccounts($invoiceData, NULL);
    // Default is the xero_default_revenue_account setting, which defaults to 200.
    $this->assertEquals('200', (string) $result[0]['LineItems']['LineItem'][0]['AccountCode']);
  }

  public function testMapToAccountsNegativeTotalFlipsSignAndUsesAccpay(): void {
    $invoiceData = $this->getBasicInvoiceData();
    $invoiceData['line_items'][0]['qty'] = -1;
    $invoiceData['line_items'][0]['unit_price'] = 10;

    $result = $this->getInvoice()->callMapToAccounts($invoiceData, NULL);
    $invoice = $result[0];

    $this->assertEquals('ACCPAY', $invoice['Type']);
    $lineItem = $invoice['LineItems']['LineItem'][0];
    // Quantity is abs()'d, and UnitAmount is flipped back to positive because
    // the overall total is negative.
    $this->assertEquals(1, $lineItem['Quantity']);
    $this->assertEquals(10, $lineItem['UnitAmount']);
  }

  public function testMapToAccountsNonZeroTaxAmountSwitchesToExclusive(): void {
    $invoiceData = $this->getBasicInvoiceData();
    $invoiceData['line_items'][0]['tax_amount'] = '5.00';

    $result = $this->getInvoice()->callMapToAccounts($invoiceData, NULL);
    // Default xero_tax_mode setting is 'Inclusive', but a non-zero tax_amount
    // forces 'Exclusive' regardless of the setting.
    $this->assertEquals('Exclusive', $result[0]['LineAmountTypes']);
  }

  public function testMapToAccountsZeroTaxAmountStringDoesNotSwitchToExclusive(): void {
    $invoiceData = $this->getBasicInvoiceData();
    $invoiceData['line_items'][0]['tax_amount'] = '0.00';

    $result = $this->getInvoice()->callMapToAccounts($invoiceData, NULL);
    $this->assertEquals('Inclusive', $result[0]['LineAmountTypes']);
  }

  public function testMapCancelledWithoutUuid(): void {
    $result = $this->getInvoice()->callMapCancelled(456, NULL);

    $invoice = $result['Invoice'];
    $this->assertNull($invoice['InvoiceID']);
    $this->assertEquals('CIVI456', $invoice['InvoiceNumber']);
    $this->assertEquals('ACCREC', $invoice['Type']);
    $this->assertEquals('Cancelled', $invoice['Reference']);
    $this->assertEquals('DRAFT', $invoice['Status']);
    $this->assertEquals('Exclusive', $invoice['LineAmountTypes']);
    $this->assertEquals(0, $invoice['LineItems']['LineItem']['Quantity']);
    $this->assertEquals(0, $invoice['LineItems']['LineItem']['UnitAmount']);
    $this->assertEquals('200', (string) $invoice['LineItems']['LineItem']['AccountCode']);
  }

  public function testMapCancelledWithUuid(): void {
    $result = $this->getInvoice()->callMapCancelled(456, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result['Invoice']['InvoiceID']);
  }

}
