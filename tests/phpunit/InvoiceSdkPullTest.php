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
 * Exercises CRM_Civixero_Invoice::pullFromXero()'s new ApiException
 * classification (429 -> CRM_Civixero_Exception_XeroThrottle, everything
 * else -> unchanged ApiException) against the real xeroapi/xero-php-oauth2
 * AccountingApi with a mocked Guzzle HTTP client - mirrors ContactSdkPullTest.
 *
 * @group headless
 */
class InvoiceSdkPullTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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

  private function pull(InvoiceSdkPullTestable $invoice): array {
    return $invoice->pullFromXero(FALSE, FALSE, '', 1, 100, '-1 week', '', '', '');
  }

  public function testPullFromXeroThrowsThrottleExceptionOnRateLimitResponse(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => ['90']], json_encode(['Message' => 'Too many requests'])));
    $invoice = $this->getInvoiceWithMockClient();

    $before = time();
    try {
      $this->pull($invoice);
      $this->fail('Expected CRM_Civixero_Exception_XeroThrottle to be thrown');
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      $this->assertGreaterThanOrEqual($before + 90, $e->getRetryAfter());
      $this->assertLessThanOrEqual($before + 91, $e->getRetryAfter());
    }
  }

  public function testPullFromXeroPropagatesApiExceptionWithCodeOnAuthFailure(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(403, [], json_encode(['Message' => 'Forbidden'])));
    $invoice = $this->getInvoiceWithMockClient();

    try {
      $this->pull($invoice);
      $this->fail('Expected ApiException to be thrown');
    }
    catch (\XeroAPI\XeroPHP\ApiException $e) {
      $this->assertEquals(403, $e->getCode());
    }
  }

  public function testPullFromXeroLogsAndRethrowsOnOtherApiError(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(500, [], json_encode(['Message' => 'Internal error'])));
    $invoice = $this->getInvoiceWithMockClient();

    $this->expectException(\XeroAPI\XeroPHP\ApiException::class);
    $this->pull($invoice);
  }

}
