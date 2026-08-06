<?php

use Civi\InvoiceSdkPushTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\GuzzleTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises pushViaApi() (added by civixero PR #215, the Xero-SDK migration)
 * against the real xeroapi/xero-php-oauth2 AccountingApi with a mocked
 * Guzzle HTTP client (Civi\Test\GuzzleTestTrait, the same pattern core uses
 * for testing payment-gateway integrations) - so the SDK's own request
 * building/serialization and response deserialization run for real, only
 * the network call itself is faked.
 *
 * @group headless
 */
class InvoiceSdkPushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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

  private function getMappedInvoice(array $overrides = []): array {
    return [$overrides + [
      'Type' => 'ACCREC',
      'Contact' => ['ContactID' => '11111111-1111-1111-1111-111111111111'],
      'Date' => '2024-03-15',
      'DueDate' => '2024-03-15',
      'Status' => 'SUBMITTED',
      'InvoiceNumber' => 'CIVI123',
      'CurrencyCode' => 'NZD',
      'Reference' => 'Test invoice',
      'LineAmountTypes' => 'Inclusive',
      'LineItems' => [
        'LineItem' => [
          [
            'Description' => 'General Admission',
            'Quantity' => 1,
            'UnitAmount' => 50,
            'AccountCode' => '400',
          ],
        ],
      ],
    ]];
  }

  private function getInvoiceWithMockClient(): InvoiceSdkPushTestable {
    $this->setUpClientWithHistoryContainer();
    $invoice = new InvoiceSdkPushTestable([]);
    $invoice->mockClient = $this->getGuzzleClient();
    return $invoice;
  }

  public function testPushToXeroSuccessReturnsLegacyShapedInvoiceResult(): void {
    $this->createMockHandler([
      json_encode([
        'Invoices' => [
          [
            'InvoiceID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'Type' => 'ACCREC',
            'Status' => 'AUTHORISED',
            'UpdatedDateUTC' => '2024-03-15T10:00:00',
            'InvoiceNumber' => 'CIVI123',
          ],
        ],
      ]),
    ]);
    $invoice = $this->getInvoiceWithMockClient();

    $result = $invoice->callPushToXero($this->getMappedInvoice(), 0);

    $this->assertEquals(
      'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      $result['Invoices']['Invoice']['InvoiceID']
    );
    $this->assertEquals('AUTHORISED', $result['Invoices']['Invoice']['Status']);
    $this->assertEquals('2024-03-15 10:00:00', $result['Invoices']['Invoice']['UpdatedDateUTC']);
  }

  public function testPushToXeroSendsIdempotencyKeyUnderXeroLimit(): void {
    $this->createMockHandler([
      json_encode([
        'Invoices' => [
          ['InvoiceID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Status' => 'AUTHORISED', 'UpdatedDateUTC' => '2024-03-15T10:00:00'],
        ],
      ]),
    ]);
    $invoice = $this->getInvoiceWithMockClient();

    $invoice->callPushToXero($this->getMappedInvoice(), 0);

    $headers = $this->getRequestHeaders();
    $this->assertCount(1, $headers);
    $this->assertArrayHasKey('Idempotency-Key', $headers[0]);
    $key = $headers[0]['Idempotency-Key'][0];
    $this->assertLessThanOrEqual(128, strlen($key));
    $this->assertStringStartsWith('civixero-invoice-CIVI123-', $key);
  }

  public function testPushToXeroRejectsMalformedStoredInvoiceIdBeforeCallingXero(): void {
    $invoice = $this->getInvoiceWithMockClient();
    // No response queued on the mock handler - if this reaches the HTTP
    // layer at all, the test will fail with a MockHandler "no more
    // responses" error rather than the expected CRM_Core_Exception,
    // proving the GUID check happens client-side before any request goes out.
    $mapped = $this->getMappedInvoice(['InvoiceID' => 'not-a-guid']);

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not a valid Xero ID/');
    $invoice->callPushToXero($mapped, 0);
  }

  /**
   * This is the seam PR #215 actually changes: pushViaApi() (unlike the
   * legacy pushToXero()) returns a top-level 'ValidationErrors' key on a
   * Xero validation failure - see InvoiceResponseHandlingTest for whether
   * savePushResponse()/validateResponse() correctly recognise that shape.
   */
  public function testPushToXeroValidationFailureReturnsTopLevelValidationErrorsShape(): void {
    $this->createMockHandler([
      json_encode([
        'Invoices' => [
          [
            'Type' => 'ACCREC',
            'Status' => 'DRAFT',
            'ValidationErrors' => [
              ['Message' => 'Account code must be specified'],
            ],
          ],
        ],
      ]),
    ]);
    $invoice = $this->getInvoiceWithMockClient();

    $result = $invoice->callPushToXero($this->getMappedInvoice(), 0);

    $this->assertEquals(['ValidationErrors' => ['Account code must be specified']], $result);
  }

  /**
   * Characterizes a gap introduced by PR #215: pushToXero()'s catch blocks
   * catch \XeroAPI\XeroPHP\ApiException (the new SDK's HTTP-error exception,
   * e.g. for a 429 rate-limit response) and translate a 429 into
   * CRM_Civixero_Exception_XeroThrottle, same as the legacy path did via
   * XeroThrottleException - so push()'s throttle-abort-and-backoff handling
   * (see InvoicePushTest::testPushAbortsRemainingRecordsAndSetsRateLimitOnThrottle)
   * keeps working once pushToXero() is switched to the SDK.
   */
  public function testPushToXeroTranslatesNewSdk429ResponseToThrottleException(): void {
    $this->createMockHandler([]);
    // createMockHandler() only builds 200s - queue a 429 directly.
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '120'], json_encode(['Message' => 'Rate limit exceeded'])));
    $invoice = $this->getInvoiceWithMockClient();

    try {
      $invoice->callPushToXero($this->getMappedInvoice(), 0);
      $this->fail('Expected a CRM_Civixero_Exception_XeroThrottle to be thrown');
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      $this->assertGreaterThan(time(), $e->getRetryAfter());
    }
  }

  public function testPushToXeroTranslatesOtherNewSdkApiExceptionsToCoreException(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(500, [], json_encode(['Message' => 'Internal error'])));
    $invoice = $this->getInvoiceWithMockClient();

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/Synchronization error/');
    $invoice->callPushToXero($this->getMappedInvoice(), 0);
  }

}
