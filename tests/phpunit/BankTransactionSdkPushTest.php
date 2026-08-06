<?php

use Civi\BankTransactionSdkPushTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\GuzzleTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class BankTransactionSdkPushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use GuzzleTestTrait;

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

  /**
   * CRM_Civixero_BankTransaction no longer overrides pushToXero() after
   * PR #215 - it inherits CRM_Civixero_Invoice::pushToXero(), which calls
   * $this->pushViaApi(). Invoice and BankTransaction each declared their own
   * *private* pushViaApi() - and private methods aren't virtual/overridable
   * in PHP, so $this->pushViaApi() called from code textually defined in
   * Invoice.php always resolved to Invoice::pushViaApi(), never
   * BankTransaction::pushViaApi(), regardless of $this's actual class. That
   * routed every BankTransaction push through Invoice's SDK model instead of
   * BankTransaction's, crashing on every single push because 'RECEIVE' (a
   * valid BankTransaction Type) isn't a valid Invoice Type.
   *
   * Fixed by making pushViaApi() protected in both classes so
   * BankTransaction's override is actually dispatched. This test proves the
   * BankTransactions endpoint (not Invoices) is hit and the response is
   * shaped correctly.
   */
  public function testBankTransactionPushHitsBankTransactionsEndpointAndReturnsLegacyShape(): void {
    $this->createMockHandler([
      json_encode([
        'BankTransactions' => [
          ['BankTransactionID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Status' => 'AUTHORISED', 'UpdatedDateUTC' => '2024-03-15T10:00:00'],
        ],
      ]),
    ]);
    $this->setUpClientWithHistoryContainer();
    $bankTransaction = new BankTransactionSdkPushTestable([]);
    $bankTransaction->mockClient = $this->getGuzzleClient();

    $mapped = [[
      'Type' => 'RECEIVE',
      'Contact' => ['ContactNumber' => 55],
      'Date' => '2024-03-15',
      'Status' => 'AUTHORISED',
      'CurrencyCode' => 'NZD',
      'Reference' => 'Test',
      'LineAmountTypes' => 'Inclusive',
      'LineItems' => ['LineItem' => [['Description' => 'Donation', 'Quantity' => 1, 'UnitAmount' => 25, 'AccountCode' => '400']]],
      'BankAccount' => ['Code' => '090'],
    ]];

    $result = $bankTransaction->callPushToXero($mapped, 0);

    $urls = $this->getRequestUrls();
    $this->assertCount(1, $urls);
    $this->assertStringContainsString('/BankTransactions', $urls[0]);
    $this->assertEquals(
      'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      $result['BankTransactions']['BankTransaction']['BankTransactionID']
    );
  }

}
