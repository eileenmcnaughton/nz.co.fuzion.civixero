<?php

use Civi\ContactSdkPushTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\GuzzleTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises pushViaApi() against the real xeroapi/xero-php-oauth2
 * AccountingApi with a mocked Guzzle HTTP client (Civi\Test\GuzzleTestTrait,
 * the same pattern core uses for testing payment-gateway integrations, and
 * the same pattern used for InvoiceSdkPushTest) - so the SDK's own request
 * building/serialization and response deserialization run for real, only
 * the network call itself is faked.
 *
 * @group headless
 */
class ContactSdkPushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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

  private function getMappedContact(array $overrides = []): array {
    return $overrides + [
      'Name' => 'Jane Doe - 123',
      'FirstName' => 'Jane',
      'LastName' => 'Doe',
      'EmailAddress' => 'jane@example.com',
      'ContactNumber' => 123,
    ];
  }

  private function getContactWithMockClient(): ContactSdkPushTestable {
    $this->setUpClientWithHistoryContainer();
    $contact = new ContactSdkPushTestable([]);
    $contact->mockClient = $this->getGuzzleClient();
    return $contact;
  }

  public function testPushToXeroSuccessReturnsLegacyShapedContactResult(): void {
    $this->createMockHandler([
      json_encode([
        'Contacts' => [
          [
            'ContactID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'Name' => 'Jane Doe - 123',
            'ContactNumber' => '123',
            'UpdatedDateUTC' => '2024-03-15T10:00:00',
          ],
        ],
      ]),
    ]);
    $contact = $this->getContactWithMockClient();

    $result = $contact->callPushToXero($this->getMappedContact(), 0);

    $this->assertEquals(
      'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      $result['Contacts']['Contact']['ContactID']
    );
    $this->assertEquals('Jane Doe - 123', $result['Contacts']['Contact']['Name']);
    $this->assertEquals('2024-03-15 10:00:00', $result['Contacts']['Contact']['UpdatedDateUTC']);
  }

  public function testPushSendsTheMappedContact(): void {
    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->setValues(['contact_type' => 'Organization', 'organization_name' => 'Example Organization'])
      ->execute()
      ->first()['id'];
    \Civi\Api4\AccountContact::save(FALSE)
      ->setMatch(['contact_id', 'plugin', 'connector_id'])
      ->addRecord([
        'contact_id' => $contactID,
        'plugin' => 'xero',
        'connector_id' => 0,
        'accounts_needs_update' => TRUE,
      ])
      ->execute();
    $this->createMockHandler([
      json_encode([
        'Contacts' => [
          ['ContactID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Name' => 'Example Organization', 'UpdatedDateUTC' => '2024-03-15T10:00:00'],
        ],
      ]),
    ]);

    $this->getContactWithMockClient()->push(['connector_id' => 0]);

    $sent = json_decode($this->getRequestBodies()[0], TRUE)['Contacts'][0];
    $this->assertSame('Example Organization', $sent['Name']);
    $this->assertSame((string) $contactID, $sent['ContactNumber']);
  }

  public function testPushToXeroSendsIdempotencyKeyUnderXeroLimit(): void {
    $this->createMockHandler([
      json_encode([
        'Contacts' => [
          ['ContactID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Name' => 'Jane Doe - 123', 'UpdatedDateUTC' => '2024-03-15T10:00:00'],
        ],
      ]),
    ]);
    $contact = $this->getContactWithMockClient();

    $contact->callPushToXero($this->getMappedContact(), 0);

    $headers = $this->getRequestHeaders();
    $this->assertCount(1, $headers);
    $this->assertArrayHasKey('Idempotency-Key', $headers[0]);
    $key = $headers[0]['Idempotency-Key'][0];
    $this->assertLessThanOrEqual(128, strlen($key));
    $this->assertStringStartsWith('civixero-contact-123-', $key);
  }

  public function testPushToXeroRejectsMalformedStoredContactIdBeforeCallingXero(): void {
    $contact = $this->getContactWithMockClient();
    // No response queued on the mock handler - if this reaches the HTTP
    // layer at all, the test will fail with a MockHandler "no more
    // responses" error rather than the expected CRM_Core_Exception,
    // proving the GUID check happens client-side before any request goes out.
    $mapped = $this->getMappedContact(['ContactID' => 'not-a-guid']);

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not a valid Xero ID/');
    $contact->callPushToXero($mapped, 0);
  }

  /**
   * This is the seam the SDK migration actually changes: pushViaApi()
   * (unlike the legacy pushToXero()) returns a top-level 'ValidationErrors'
   * key on a Xero validation failure - Base::validateResponse() already
   * recognises that shape (added for Invoice/BankTransaction's migration),
   * so push()'s error handling needs no changes.
   */
  public function testPushToXeroValidationFailureReturnsTopLevelValidationErrorsShape(): void {
    $this->createMockHandler([
      json_encode([
        'Contacts' => [
          [
            'Name' => 'Jane Doe - 123',
            'ValidationErrors' => [
              ['Message' => 'Email address must be valid'],
            ],
          ],
        ],
      ]),
    ]);
    $contact = $this->getContactWithMockClient();

    $result = $contact->callPushToXero($this->getMappedContact(), 0);

    $this->assertEquals(['ValidationErrors' => ['Email address must be valid']], $result);
  }

  /**
   * pushToXero()'s catch blocks catch \XeroAPI\XeroPHP\ApiException (the SDK's
   * HTTP-error exception, e.g. for a 429 rate-limit response) and translate
   * a 429 into CRM_Civixero_Exception_XeroThrottle, mirroring
   * Invoice::pushToXero() - so push()'s throttle-abort-and-backoff handling
   * (see ContactPushTest::testPushAbortsRemainingRecordsAndSetsRateLimitOnThrottle)
   * keeps working once pushToXero() delegates to the SDK.
   */
  public function testPushToXeroTranslatesSdk429ResponseToThrottleException(): void {
    $this->createMockHandler([]);
    // createMockHandler() only builds 200s - queue a 429 directly.
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '120'], json_encode(['Message' => 'Rate limit exceeded'])));
    $contact = $this->getContactWithMockClient();

    try {
      $contact->callPushToXero($this->getMappedContact(), 0);
      $this->fail('Expected a CRM_Civixero_Exception_XeroThrottle to be thrown');
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      $this->assertGreaterThan(time(), $e->getRetryAfter());
    }
  }

  public function testPushToXeroTranslatesOtherSdkApiExceptionsToCoreException(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(500, [], json_encode(['Message' => 'Internal error'])));
    $contact = $this->getContactWithMockClient();

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/Synchronization error/');
    $contact->callPushToXero($this->getMappedContact(), 0);
  }

}
