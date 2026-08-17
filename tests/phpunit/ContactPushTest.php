<?php

use Civi\ContactPushTestable;
use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\ContactTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end characterization tests for CRM_Civixero_Contact::push().
 *
 * push() orchestrates: fetch queued AccountContacts -> map -> pushToXero() ->
 * inline response-handling/dedupe. pushToXero() is the only piece the
 * Xero-SDK migration touches, so these tests use ContactPushTestable to feed
 * it canned responses/exceptions - proving push()'s surrounding
 * orchestration (error handling, throttle abort, dedupe, DB updates) is
 * independent of which client pushToXero() delegates to underneath.
 *
 * @group headless
 */
class ContactPushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;
  use ContactTestTrait;

  /**
   * Set by testPushSkipsContactWhenMapToAccountsHookVetoes() to make
   * hook_civicrm_accountPushAlterMapped() veto the push for this test only.
   *
   * @var bool
   */
  public bool $vetoPush = FALSE;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp():void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    CRM_Civixero_Base::resetApiRateLimitExceeded();
    $this->vetoPush = FALSE;
    parent::setUp();
  }

  public function tearDown(): void {
    CRM_Civixero_Base::resetApiRateLimitExceeded();
    parent::tearDown();
  }

  /**
   * hook_civicrm_accountPushAlterMapped - only vetoes when $this->vetoPush is set.
   */
  public function hook_civicrm_accountPushAlterMapped($entity, &$data, &$save, &$params) {
    if ($this->vetoPush) {
      $save = FALSE;
    }
  }

  /**
   * Test push.
   *
   * No check_permissions is passed, matching how the scheduled "CiviXero
   * Contact Push Job" invokes this API internally - permission checks must
   * not apply in that context.
   */
  public function testPush():void {
    $this->callAPISuccess('Civixero', 'contactpush');
  }

  /**
   * push() builds its worklist from getContactsRequiringPushUpdate(); when
   * called with a contact_id it should return only that contact's queued
   * AccountContact record, not other contacts that are also queued.
   */
  public function testGetContactsRequiringPushUpdateIsScopedToOneContact(): void {
    $targetContactID = $this->individualCreate([], 'target');
    $otherContactID = $this->individualCreate([], 'other');

    $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $targetContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_needs_update' => 1,
    ]);
    $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $otherContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_needs_update' => 1,
    ]);

    $records = (new CRM_Civixero_Contact([]))->getContactsRequiringPushUpdate([
      'connector_id' => 0,
      'contact_id' => $targetContactID,
    ], 10);

    $this->assertCount(1, $records);
    $this->assertEquals($targetContactID, $records[0]['contact_id']);
  }

  /**
   * Create a Contact + queued AccountContact, ready for
   * CRM_Civixero_Contact::push() to pick up.
   *
   * @return array{contact_id: int, account_contact_id: int}
   */
  private function createQueuedAccountContact(): array {
    $contactID = $this->individualCreate();
    // accountsync's own hook_civicrm_post may have already auto-created an
    // AccountContact row for this contact (depending on this site's
    // account_sync settings) - update that row rather than colliding with
    // its unique index by inserting a second one.
    $existing = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('connector_id', '=', 0)
      ->addWhere('plugin', '=', 'xero')
      ->execute();
    $params = [
      'contact_id' => $contactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_needs_update' => 1,
    ];
    if ($existing->count() > 0) {
      $params['id'] = $existing->first()['id'];
    }
    $accountContact = $this->callAPISuccess('AccountContact', 'create', $params);
    return [
      'contact_id' => $contactID,
      'account_contact_id' => $accountContact['id'],
    ];
  }

  private function getCannedXeroContactResult(string $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'): array {
    return [
      'result' => [
        'Contacts' => [
          'Contact' => [
            'ContactID' => $xeroContactID,
            'UpdatedDateUTC' => '2024-03-15 10:00:00',
            'Name' => 'Test Contact',
          ],
        ],
      ],
    ];
  }

  public function testPushSuccessUpdatesAccountContact(): void {
    $fixture = $this->createQueuedAccountContact();
    $contact = new ContactPushTestable([]);
    $contact->pushToXeroQueue[] = $this->getCannedXeroContactResult();

    $result = $contact->push(['connector_id' => 0], 10);

    $this->assertTrue($result);
    $this->assertCount(1, $contact->pushToXeroCalls);
    // Read via API4 - API3's AccountContact.get runs accounts_data through
    // CRM_Accountsync_Hook::mapAccountsData(), which unconditionally reads
    // $accountsData['Addresses']/['Phones'] and warns on our minimal canned
    // snapshot; that's an unrelated pre-existing quirk, not something this
    // migration touches.
    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $fixture['account_contact_id'])
      ->execute()
      ->single();
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $saved['accounts_contact_id']);
    $this->assertEquals('Test Contact', $saved['accounts_display_name']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testPushWithNoQueuedContactsReturnsTrueWithoutCallingPushToXero(): void {
    $contact = new ContactPushTestable([]);
    $result = $contact->push(['connector_id' => 0], 10);
    $this->assertTrue($result);
    $this->assertCount(0, $contact->pushToXeroCalls);
  }

  public function testPushSetsDoNotSyncWhenContactIsDeleted(): void {
    $fixture = $this->createQueuedAccountContact();
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $fixture['contact_id'])
      ->addValue('is_deleted', TRUE)
      ->execute();
    $contact = new ContactPushTestable([]);

    $result = $contact->push(['connector_id' => 0], 10);

    $this->assertTrue($result);
    $this->assertCount(0, $contact->pushToXeroCalls);
    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $fixture['account_contact_id'])
      ->execute()
      ->single();
    $this->assertEquals(1, $saved['do_not_sync']);
  }

  public function testPushThrowsWhenXeroContactAlreadyLinkedToDifferentLiveContact(): void {
    $fixtureA = $this->createQueuedAccountContact();
    $otherContactID = $this->individualCreate();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    // Existing AccountContact row already linking that Xero ContactID to a
    // DIFFERENT (live) CiviCRM contact.
    $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $otherContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      // Already synced - not itself queued for push, so this test only
      // exercises the dedupe/conflict check on fixtureA's push.
      'accounts_needs_update' => 0,
    ]);
    $contact = new ContactPushTestable([]);
    $contact->pushToXeroQueue[] = $this->getCannedXeroContactResult($xeroContactID);

    try {
      $contact->push(['connector_id' => 0], 10);
      $this->fail('Expected push() to throw because the Xero contact is already linked elsewhere');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Not all contacts were saved', $e->getMessage());
    }

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $fixtureA['account_contact_id'])
      ->execute()
      ->single();
    $this->assertNotEmpty($saved['error_data']);
    $this->assertStringContainsString('Attempt to sync Contact', $saved['error_data']);
  }

  public function testPushRepairsStaleAccountContactRowWhenMatchedContactIsDeleted(): void {
    $fixtureA = $this->createQueuedAccountContact();
    $deletedContactID = $this->individualCreate();
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $deletedContactID)
      ->addValue('is_deleted', TRUE)
      ->execute();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $staleRow = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $deletedContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      // Already synced - not itself queued for push. (accounts_needs_update
      // defaults to TRUE; leaving it set would queue this row too, and
      // since its own contact is deleted, its is_deleted branch would mark
      // do_not_sync=TRUE and clobber the repair this test is checking for.)
      'accounts_needs_update' => 0,
    ]);
    $contact = new ContactPushTestable([]);
    $contact->pushToXeroQueue[] = $this->getCannedXeroContactResult($xeroContactID);

    $result = $contact->push(['connector_id' => 0], 10);

    $this->assertTrue($result);
    // fixtureA's original row was deleted; the stale row was repaired
    // in-place and now carries fixtureA's contact.
    $remaining = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', 'IN', [$fixtureA['account_contact_id'], $staleRow['id']])
      ->execute();
    $this->assertCount(1, $remaining);
    $repaired = $remaining->first();
    $this->assertEquals($staleRow['id'], $repaired['id']);
    $this->assertEquals($fixtureA['contact_id'], $repaired['contact_id']);
    $this->assertEquals(0, $repaired['do_not_sync']);
    $this->assertEquals($xeroContactID, $repaired['accounts_contact_id']);
  }

  public function testPushRecordsErrorAndContinuesWhenPushToXeroThrowsCoreException(): void {
    $fixtureA = $this->createQueuedAccountContact();
    $fixtureB = $this->createQueuedAccountContact();
    $contact = new ContactPushTestable([]);
    // getContactsRequiringPushUpdate() orders by error_data (nulls first),
    // so insertion order among two fresh (error_data IS NULL) rows isn't
    // guaranteed - queue the same failure/success pair regardless of which
    // fixture is processed first, and assert on totals instead of identity.
    $contact->pushToXeroQueue[] = ['throw' => new CRM_Core_Exception('Xero rejected the contact')];
    $contact->pushToXeroQueue[] = $this->getCannedXeroContactResult();

    try {
      $contact->push(['connector_id' => 0], 10);
      $this->fail('Expected push() to throw because one record failed');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Not all contacts were saved', $e->getMessage());
    }

    // Both records were attempted (the failure didn't abort the loop).
    $this->assertCount(2, $contact->pushToXeroCalls);
    $accountContacts = (array) \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', 'IN', [$fixtureA['account_contact_id'], $fixtureB['account_contact_id']])
      ->execute();
    $withError = array_filter($accountContacts, fn($r) => !empty($r['error_data'] ?? NULL));
    $withoutError = array_filter($accountContacts, fn($r) => empty($r['error_data'] ?? NULL));
    $this->assertCount(1, $withError);
    $this->assertCount(1, $withoutError);
    $failed = reset($withError);
    $this->assertStringContainsString('Xero rejected the contact', $failed['error_data']);
    // Still queued for retry - a generic CRM_Core_Exception isn't a
    // permanent failure (accounts_needs_update is never cleared on this path).
    $this->assertEquals(1, $failed['accounts_needs_update']);
  }

  public function testPushAbortsRemainingRecordsAndSetsRateLimitOnThrottle(): void {
    $this->createQueuedAccountContact();
    $this->createQueuedAccountContact();
    $contact = new ContactPushTestable([]);
    $contact->pushToXeroQueue[] = ['throw' => new CRM_Civixero_Exception_XeroThrottle('Rate limited', 429, NULL, time() + 3600)];

    try {
      $contact->push(['connector_id' => 0], 10);
      $this->fail('Expected push() to throw because Xero throttled the request');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Contact Push aborted due to throttling by Xero', $e->getMessage());
    }

    // The throttle exception aborts the whole loop - the second record is
    // never attempted.
    $this->assertCount(1, $contact->pushToXeroCalls);
    $this->assertNotEmpty(Civi::settings()->get('xero_oauth_rate_exceeded'));
  }

  public function testPushSkipsContactWhenMapToAccountsHookVetoes(): void {
    $fixture = $this->createQueuedAccountContact();
    $this->vetoPush = TRUE;
    $contact = new ContactPushTestable([]);

    $result = $contact->push(['connector_id' => 0], 10);

    $this->assertTrue($result);
    $this->assertCount(0, $contact->pushToXeroCalls);
    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $fixture['account_contact_id'])
      ->execute()
      ->single();
    $this->assertStringContainsString('Ignored via accountPushAlterMapped hook', $saved['error_data']);
    // The hook explicitly chose to exclude this contact - not a real error.
    $this->assertEquals(1, $saved['is_error_resolved']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

}
