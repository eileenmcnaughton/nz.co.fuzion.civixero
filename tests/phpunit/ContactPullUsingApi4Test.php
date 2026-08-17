<?php

use Civi\ContactSdkPullTestable;
use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\ContactTestTrait;
use Civi\Test\GuzzleTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CRM_Civixero_Contact::pullUsingApi4()'s paging loop directly -
 * ContactSdkPullTestable (from ContactSdkPullTest) is reused unchanged here:
 * pullUsingApi4() now calls $this->pullFromXero() itself rather than going
 * through the Civi\Api4\Xero::contactPull() action (which always constructed
 * its own, un-mockable CRM_Civixero_Contact instance), so the existing mocked
 * Guzzle client override reaches pullUsingApi4() too.
 *
 * @group headless
 */
class ContactPullUsingApi4Test extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use GuzzleTestTrait;
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
    parent::setUp();
  }

  private function getContactWithMockClient(): ContactSdkPullTestable {
    $this->setUpClientWithHistoryContainer();
    $contact = new ContactSdkPullTestable([]);
    $contact->mockClient = $this->getGuzzleClient();
    return $contact;
  }

  private function xeroContactJson(string $contactID, string $name, string $contactNumber = ''): string {
    return json_encode([
      'Contacts' => [
        [
          'ContactID' => $contactID,
          'Name' => $name,
          'ContactNumber' => $contactNumber,
          'UpdatedDateUTC' => '2024-03-15T10:00:00',
        ],
      ],
    ]);
  }

  private function emptyContactsJson(): string {
    return json_encode(['Contacts' => []]);
  }

  public function testRateLimitOnFirstPageStopsPagingAndSetsBreaker(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => ['120']], json_encode(['Message' => 'Too many requests'])));
    $contact = $this->getContactWithMockClient();

    $before = time();
    try {
      $contact->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);
      $this->fail('Expected CRM_Core_Exception to be thrown');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('rate limited', $e->getMessage());
    }
    $this->assertCount(1, $this->getContainer());
    $retryAfter = \Civi::settings()->get('xero_retry_after');
    $this->assertGreaterThanOrEqual($before + 120, $retryAfter);
    $this->assertLessThanOrEqual($before + 121, $retryAfter);
  }

  public function testAuthFailureOnFirstPageAbortsImmediately(): void {
    $this->createMockHandler([]);
    $this->getMockHandler()->append(new \GuzzleHttp\Psr7\Response(403, [], json_encode(['Message' => 'Forbidden'])));
    $contact = $this->getContactWithMockClient();

    try {
      $contact->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);
      $this->fail('Expected CRM_Core_Exception to be thrown');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Authentication with Xero failed', $e->getMessage());
    }
    // Only the first (failing) page was ever requested.
    $this->assertCount(1, $this->getContainer());
  }

  public function testPerContactErrorOnOnePageDoesNotBlockLaterPages(): void {
    $matchedContactID = $this->individualCreate();
    $duplicateXeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    // Two existing AccountContact rows that both match the incoming Xero
    // contact below (one via ContactNumber->contact_id, one via
    // accounts_contact_id) - reproduces processPull()'s duplicate-match
    // error on page 1, same fixture shape as ContactPullTest's equivalent.
    $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $matchedContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
    ]);
    $this->callAPISuccess('AccountContact', 'create', [
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $duplicateXeroContactID,
    ]);

    $this->createMockHandler([
      $this->xeroContactJson($duplicateXeroContactID, 'Duplicate Contact', (string) $matchedContactID),
      $this->xeroContactJson('bbbbbbbb-cccc-dddd-eeee-ffffffffffff', 'Clean Contact'),
      $this->emptyContactsJson(),
    ]);
    $contact = $this->getContactWithMockClient();

    try {
      $contact->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);
      $this->fail('Expected CRM_Core_Exception to be thrown');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Page 1', $e->getMessage());
    }
    // All 3 pages were requested - the page 1 error didn't strand page 2/3.
    $this->assertCount(3, $this->getContainer());
    $clean = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff')
      ->execute()
      ->single();
    $this->assertEquals('Clean Contact', $clean['accounts_display_name']);
  }

  public function testCleanMultiPagePullDoesNotThrow(): void {
    $this->createMockHandler([
      $this->xeroContactJson('cccccccc-cccc-cccc-cccc-cccccccccccc', 'Page One Contact'),
      $this->xeroContactJson('dddddddd-dddd-dddd-dddd-dddddddddddd', 'Page Two Contact'),
      $this->emptyContactsJson(),
    ]);
    $contact = $this->getContactWithMockClient();

    $contact->pullUsingApi4(['start_date' => '-1 week', 'connector_id' => 0]);

    $this->assertCount(3, $this->getContainer());
    foreach (['cccccccc-cccc-cccc-cccc-cccccccccccc', 'dddddddd-dddd-dddd-dddd-dddddddddddd'] as $xeroID) {
      $saved = \Civi\Api4\AccountContact::get(FALSE)
        ->addWhere('accounts_contact_id', '=', $xeroID)
        ->execute();
      $this->assertCount(1, $saved);
    }
  }

}
