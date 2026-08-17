<?php

use Civi\InvoiceSdkPullTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\GuzzleTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CRM_Civixero_Invoice::pullUsingApi4()'s paging loop directly -
 * mirrors ContactPullUsingApi4Test. pullUsingApi4() now calls
 * $this->pullFromXero() itself rather than going through the
 * Civi\Api4\Xero::invoicePull() action (which always constructed its own,
 * un-mockable CRM_Civixero_Invoice instance), so the existing mocked Guzzle
 * client override reaches pullUsingApi4() too.
 *
 * The "one page's per-record error doesn't block later pages" case is
 * already covered end-to-end for Contact pull (ContactPullUsingApi4Test) -
 * the underlying mechanism in pullUsingApi4() is identical code for both
 * classes. Invoice::processPull()'s own per-record error paths are all
 * defensively guarded (e.g. an unresolvable contribution_id is detected and
 * stripped before save, rather than left to fail as a DB error), so there is
 * no natural, non-contrived way to trigger one here - not reproduced
 * separately for Invoice.
 *
 * @group headless
 */
class InvoicePullUsingApi4Test extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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

  private function getInvoiceWithMockClient(): InvoiceSdkPullTestable {
    $this->setUpClientWithHistoryContainer();
    $invoice = new InvoiceSdkPullTestable([]);
    $invoice->mockClient = $this->getGuzzleClient();
    return $invoice;
  }

  private function xeroInvoiceJson(string $invoiceID, string $invoiceNumber): string {
    return json_encode([
      'Invoices' => [
        [
          'InvoiceID' => $invoiceID,
          'Type' => 'ACCREC',
          'InvoiceNumber' => $invoiceNumber,
          'Status' => 'AUTHORISED',
          'Date' => '2024-03-15T00:00:00',
          'DueDate' => '2024-03-29T00:00:00',
          'UpdatedDateUTC' => '2024-03-15T10:00:00',
        ],
      ],
    ]);
  }

  private function emptyInvoicesJson(): string {
    return json_encode(['Invoices' => []]);
  }

  public function testRateLimitOnFirstPageStopsPagingAndSetsBreaker(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => ['60']], json_encode(['Message' => 'Too many requests'])));
    $invoice = $this->getInvoiceWithMockClient();

    $before = time();
    try {
      $invoice->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);
      $this->fail('Expected CRM_Core_Exception to be thrown');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('rate limited', $e->getMessage());
    }
    $this->assertCount(1, $this->getContainer());
    $retryAfter = \Civi::settings()->get('xero_retry_after');
    $this->assertGreaterThanOrEqual($before + 60, $retryAfter);
    $this->assertLessThanOrEqual($before + 61, $retryAfter);
  }

  public function testAuthFailureOnFirstPageAbortsImmediately(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(401, [], json_encode(['Message' => 'Unauthorized'])));
    $invoice = $this->getInvoiceWithMockClient();

    try {
      $invoice->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);
      $this->fail('Expected CRM_Core_Exception to be thrown');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Authentication with Xero failed', $e->getMessage());
    }
    $this->assertCount(1, $this->getContainer());
  }

  public function testCleanMultiPagePullDoesNotThrow(): void {
    $this->createMockHandler([
      $this->xeroInvoiceJson('cccccccc-cccc-cccc-cccc-cccccccccccc', 'INV-0001'),
      $this->xeroInvoiceJson('dddddddd-dddd-dddd-dddd-dddddddddddd', 'INV-0002'),
      $this->emptyInvoicesJson(),
    ]);
    $invoice = $this->getInvoiceWithMockClient();

    $invoice->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);

    $this->assertCount(3, $this->getContainer());
    foreach (['cccccccc-cccc-cccc-cccc-cccccccccccc', 'dddddddd-dddd-dddd-dddd-dddddddddddd'] as $xeroID) {
      $saved = \Civi\Api4\AccountInvoice::get(FALSE)
        ->addWhere('accounts_invoice_id', '=', $xeroID)
        ->execute();
      $this->assertCount(1, $saved);
    }
  }

}
