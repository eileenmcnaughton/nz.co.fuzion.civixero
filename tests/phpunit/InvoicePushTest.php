<?php

use Civi\InvoicePushTestable;
use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\ContactTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end characterization tests for CRM_Civixero_Invoice::push().
 *
 * push() orchestrates: fetch queued AccountInvoices -> map -> pushToXero() ->
 * savePushResponse(). pushToXero() is the only piece the Xero-SDK migration
 * touches, so these tests use InvoicePushTestable to feed it canned
 * responses/exceptions - proving push()'s surrounding orchestration
 * (error handling, throttle abort, DB updates) is independent of which SDK
 * pushToXero() delegates to underneath.
 *
 * @group headless
 */
class InvoicePushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;
  use ContactTestTrait;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    CRM_Civixero_Base::resetApiRateLimitExceeded();
    // Pin down the invoice-due-date-from-settings branch so it can't leak
    // in from whatever this site's Contribute settings happen to be (and so
    // mapToAccounts() doesn't hit PHP 8.1's deprecated strftime()).
    Civi::settings()->set('invoice_due_date', 0);
    Civi::settings()->set('invoice_due_date_period', 'select');
    parent::setUp();
  }

  public function tearDown(): void {
    CRM_Civixero_Base::resetApiRateLimitExceeded();
    parent::tearDown();
  }

  /**
   * Create a Contribution + AccountContact + queued AccountInvoice, ready
   * for CRM_Civixero_Invoice::push() to pick up.
   *
   * @return array{contribution_id: int, account_invoice_id: int}
   */
  private function createQueuedAccountInvoice(): array {
    $contactID = $this->individualCreate();
    // accountsync's hook_civicrm_post may have already auto-created an
    // AccountContact row for this contact (depending on this site's
    // account_sync settings) - update that row rather than colliding with
    // its unique index by inserting a second one. accounts_contact_id must
    // also be unique per connector/plugin, so derive it from the contact ID
    // rather than reusing a fixed GUID across multiple fixtures in one test.
    $existingContact = $this->callAPISuccess('AccountContact', 'get', [
      'contact_id' => $contactID,
      'connector_id' => 0,
      'plugin' => 'xero',
    ]);
    $accountContactParams = [
      'contact_id' => $contactID,
      'accounts_contact_id' => sprintf('11111111-1111-1111-1111-%012d', $contactID),
      'plugin' => 'xero',
      'connector_id' => 0,
    ];
    if ($existingContact['count'] > 0) {
      $accountContactParams['id'] = reset($existingContact['values'])['id'];
    }
    $this->callAPISuccess('AccountContact', 'create', $accountContactParams);
    $contribution = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $contactID,
      'financial_type_id' => 1,
      'total_amount' => 50,
      'receive_date' => '2024-03-15',
      'contribution_status_id' => 'Completed',
      'source' => 'Test',
    ]);
    // accountsync's own hook_civicrm_post may have already auto-created an
    // AccountInvoice row for this contribution (depending on this site's
    // account_sync settings) - update that row rather than colliding with
    // the UI_invoice_id_plugin unique index by inserting a second one.
    $existing = $this->callAPISuccess('AccountInvoice', 'get', [
      'contribution_id' => $contribution['id'],
      'connector_id' => 0,
      'plugin' => 'xero',
    ]);
    $params = [
      'contribution_id' => $contribution['id'],
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_needs_update' => 1,
    ];
    if ($existing['count'] > 0) {
      $params['id'] = reset($existing['values'])['id'];
    }
    $accountInvoice = $this->callAPISuccess('AccountInvoice', 'create', $params);
    return [
      'contribution_id' => $contribution['id'],
      'account_invoice_id' => $accountInvoice['id'],
    ];
  }

  public function testPushSuccessUpdatesAccountInvoice(): void {
    $fixture = $this->createQueuedAccountInvoice();
    $invoice = new InvoicePushTestable([]);
    $invoice->pushToXeroQueue[] = [
      'result' => [
        'Invoices' => [
          'Invoice' => [
            'InvoiceID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'UpdatedDateUTC' => '2024-03-15 10:00:00',
            'Status' => 'AUTHORISED',
          ],
        ],
      ],
    ];

    $count = $invoice->push(['connector_id' => 0], 10);

    $this->assertEquals(1, $count);
    $this->assertCount(1, $invoice->pushToXeroCalls);
    $saved = $this->callAPISuccessGetSingle('AccountInvoice', ['id' => $fixture['account_invoice_id']]);
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $saved['accounts_invoice_id']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testPushWithNoQueuedInvoicesReturnsZeroWithoutCallingPushToXero(): void {
    $invoice = new InvoicePushTestable([]);
    $count = $invoice->push(['connector_id' => 0], 10);
    $this->assertEquals(0, $count);
    $this->assertCount(0, $invoice->pushToXeroCalls);
  }

  public function testPushRecordsErrorAndContinuesWhenPushToXeroThrowsCoreException(): void {
    $fixtureA = $this->createQueuedAccountInvoice();
    $fixtureB = $this->createQueuedAccountInvoice();
    $invoice = new InvoicePushTestable([]);
    // getAccountInvoicesToPush() orders by error_data (nulls first), so
    // insertion order among two fresh (error_data IS NULL) rows isn't
    // guaranteed - queue the same failure/success pair regardless of which
    // fixture is processed first, and assert on totals instead of identity.
    $invoice->pushToXeroQueue[] = ['throw' => new CRM_Core_Exception('Xero rejected the invoice')];
    $invoice->pushToXeroQueue[] = [
      'result' => [
        'Invoices' => [
          'Invoice' => [
            'InvoiceID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'UpdatedDateUTC' => '2024-03-15 10:00:00',
            'Status' => 'AUTHORISED',
          ],
        ],
      ],
    ];

    try {
      $invoice->push(['connector_id' => 0], 10);
      $this->fail('Expected push() to throw because one record failed');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Not all records were saved', $e->getMessage());
    }

    // Both records were attempted (the failure didn't abort the loop).
    $this->assertCount(2, $invoice->pushToXeroCalls);
    $accountInvoices = $this->callAPISuccess('AccountInvoice', 'get', [
      'id' => ['IN' => [$fixtureA['account_invoice_id'], $fixtureB['account_invoice_id']]],
    ])['values'];
    $withError = array_filter($accountInvoices, fn($r) => !empty($r['error_data'] ?? NULL));
    $withoutError = array_filter($accountInvoices, fn($r) => empty($r['error_data'] ?? NULL));
    $this->assertCount(1, $withError);
    $this->assertCount(1, $withoutError);
    $failed = reset($withError);
    $this->assertStringContainsString('Xero rejected the invoice', $failed['error_data']);
    // Still queued for retry - a generic CRM_Core_Exception isn't a permanent failure.
    $this->assertEquals(1, $failed['accounts_needs_update']);
  }

  public function testPushAbortsRemainingRecordsAndSetsRateLimitOnThrottle(): void {
    $this->createQueuedAccountInvoice();
    $this->createQueuedAccountInvoice();
    $invoice = new InvoicePushTestable([]);
    $invoice->pushToXeroQueue[] = ['throw' => new CRM_Civixero_Exception_XeroThrottle('Rate limited', 429, NULL, time() + 3600)];

    try {
      $invoice->push(['connector_id' => 0], 10);
      $this->fail('Expected push() to throw because Xero throttled the request');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Push aborted due to throttling by Xero', $e->getMessage());
    }

    // The throttle exception aborts the whole loop - the second record is
    // never attempted.
    $this->assertCount(1, $invoice->pushToXeroCalls);
    $this->assertNotEmpty(Civi::settings()->get('xero_oauth_rate_exceeded'));
  }

}
