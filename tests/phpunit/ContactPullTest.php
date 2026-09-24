<?php

use Civi\ContactPullTestable;
use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\ContactTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for CRM_Civixero_Contact::processPull() - the DB
 * orchestration that turns a pulled Xero contact (pullFromXero()'s output
 * shape) into a civicrm_account_contact row. pullFromXero() itself is
 * already on the xeroapi/xero-php-oauth2 SDK (see ContactSdkPullTest) - this
 * file has no SDK/network involvement, only DB behaviour.
 *
 * @group headless
 */
class ContactPullTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;
  use ContactTestTrait;

  /**
   * Set by tests that need hook_civicrm_accountPullPreSave() to veto or
   * mutate the save for this test only.
   *
   * @var bool
   */
  public bool $vetoPull = FALSE;

  /**
   * @var callable|null
   */
  public $mutateParamsHook = NULL;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    $this->vetoPull = FALSE;
    $this->mutateParamsHook = NULL;
    parent::setUp();
  }

  public function hook_civicrm_accountPullPreSave($entity, &$data, &$save, &$params) {
    if ($this->vetoPull) {
      $save = FALSE;
    }
    if ($this->mutateParamsHook !== NULL) {
      ($this->mutateParamsHook)($params);
    }
  }

  private function getPulledXeroContact(array $overrides = []): array {
    return $overrides + [
      'contact_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      'name' => 'Jane Doe',
      'updated_date_utc' => '2024-03-15 10:00:00',
    ];
  }

  private function backdateLastSyncDate(int $accountContactId, string $date): void {
    CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_account_contact SET last_sync_date = %1 WHERE id = %2',
      [1 => [$date, 'String'], 2 => [$accountContactId, 'Integer']]
    );
  }

  private function getLastSyncDate(int $accountContactId): string {
    return (string) CRM_Core_DAO::singleValueQuery(
      'SELECT last_sync_date FROM civicrm_account_contact WHERE id = %1',
      [1 => [$accountContactId, 'Integer']]
    );
  }

  public function testProcessPullCreatesNewAccountContactWhenNoMatch(): void {
    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([$this->getPulledXeroContact()], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute()
      ->single();
    $this->assertEquals('Jane Doe', $saved['accounts_display_name']);
    $this->assertEquals('2024-03-15 10:00:00', $saved['accounts_modified_date']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testProcessPullSkipsUpdateWhenNoTrackedFieldChanged(): void {
    $contactID = $this->individualCreate();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $existing = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $contactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      'accounts_display_name' => 'Jane Doe',
      'accounts_modified_date' => '2024-03-15 10:00:00',
      'accounts_needs_update' => 0,
    ]);
    $this->backdateLastSyncDate($existing['id'], '2020-01-01 00:00:00');

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact([
        'contact_id' => $xeroContactID,
        'name' => 'Jane Doe',
        'updated_date_utc' => '2024-03-15 10:00:00',
      ]),
    ], 0);

    // No AccountContact::update() call was issued at all - last_sync_date
    // (MySQL CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) proves it,
    // since only a real UPDATE statement would bump it off the backdated
    // value.
    $this->assertEquals('2020-01-01 00:00:00', $this->getLastSyncDate($existing['id']));
  }

  public function testProcessPullUpdatesExistingRowWhenATrackedFieldChanges(): void {
    $contactID = $this->individualCreate();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $existing = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $contactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      'accounts_display_name' => 'Old Name',
      'accounts_modified_date' => '2024-03-15 10:00:00',
      'accounts_needs_update' => 0,
    ]);
    $this->backdateLastSyncDate($existing['id'], '2020-01-01 00:00:00');

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact([
        'contact_id' => $xeroContactID,
        'name' => 'Jane Doe',
        'updated_date_utc' => '2024-03-15 10:00:00',
      ]),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $existing['id'])
      ->execute()
      ->single();
    $this->assertEquals('Jane Doe', $saved['accounts_display_name']);
    $this->assertNotEquals('2020-01-01 00:00:00', $this->getLastSyncDate($existing['id']));
  }

  /**
   * A push queued by a CiviCRM-side change (eg. a new address) must survive
   * a pull that refreshes the Xero-side data for the same contact.
   */
  public function testProcessPullKeepsPendingPushWhenATrackedFieldChanges(): void {
    $contactID = $this->individualCreate();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $existing = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $contactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      'accounts_display_name' => 'Jane Doe',
      'accounts_modified_date' => '2024-03-15 10:00:00',
      'accounts_needs_update' => 1,
    ]);

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact([
        'contact_id' => $xeroContactID,
        'name' => 'Jane Doe',
        'updated_date_utc' => '2024-03-16 09:00:00',
      ]),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $existing['id'])
      ->execute()
      ->single();
    $this->assertEquals('2024-03-16 09:00:00', $saved['accounts_modified_date']);
    $this->assertTrue($saved['accounts_needs_update']);
  }

  public function testProcessPullKeepsPendingPushWhenNothingChanged(): void {
    $contactID = $this->individualCreate();
    $xeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $existing = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $contactID,
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $xeroContactID,
      'accounts_display_name' => 'Jane Doe',
      'accounts_modified_date' => '2024-03-15 10:00:00',
      'accounts_needs_update' => 1,
    ]);
    $this->backdateLastSyncDate($existing['id'], '2020-01-01 00:00:00');

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact([
        'contact_id' => $xeroContactID,
        'name' => 'Jane Doe',
        'updated_date_utc' => '2024-03-15 10:00:00',
      ]),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('id', '=', $existing['id'])
      ->execute()
      ->single();
    $this->assertTrue($saved['accounts_needs_update']);
    $this->assertEquals('2020-01-01 00:00:00', $this->getLastSyncDate($existing['id']));
  }

  public function testProcessPullMapsValidContactNumberToContactId(): void {
    $contactID = $this->individualCreate();

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact(['contact_number' => (string) $contactID]),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute()
      ->single();
    $this->assertEquals($contactID, $saved['contact_id']);
  }

  public function testProcessPullLeavesContactIdUnsetWhenContactNumberIsNotAValidInteger(): void {
    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact(['contact_number' => 'not-a-number']),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute()
      ->single();
    $this->assertEmpty($saved['contact_id']);
  }

  public function testProcessPullUnsetsContactIdWhenNoMatchingCivicrmContactExists(): void {
    // A ContactNumber that is a syntactically valid integer but does not
    // correspond to any real CiviCRM contact.
    $bogusContactID = 999999999;

    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([
      $this->getPulledXeroContact(['contact_number' => (string) $bogusContactID]),
    ], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute()
      ->single();
    $this->assertEmpty($saved['contact_id']);
  }

  /**
   * When the OR(contact_id, accounts_contact_id) lookup matches more than
   * one existing AccountContact row, processPull() records an error on
   * every matched row and moves on to the next Xero contact in the batch -
   * it does not abort the whole pull, and the accumulated error is only
   * thrown as CRM_Core_Exception after the full batch has been attempted.
   */
  public function testProcessPullRecordsErrorOnDuplicateMatchAndContinuesBatch(): void {
    $matchedContactID = $this->individualCreate();
    $duplicateXeroContactID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    // rowA matches via contact_id (through ContactNumber), rowB matches via
    // accounts_contact_id - both satisfy the OR clause, so the lookup finds 2.
    $rowA = $this->callAPISuccess('AccountContact', 'create', [
      'contact_id' => $matchedContactID,
      'plugin' => 'xero',
      'connector_id' => 0,
    ]);
    $rowB = $this->callAPISuccess('AccountContact', 'create', [
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_contact_id' => $duplicateXeroContactID,
    ]);

    $contact = new ContactPullTestable([]);
    try {
      $contact->callProcessPull([
        $this->getPulledXeroContact([
          'contact_id' => $duplicateXeroContactID,
          'contact_number' => (string) $matchedContactID,
        ]),
        // A second, unrelated contact in the same batch - proves the
        // duplicate above didn't abort the rest of the pull.
        $this->getPulledXeroContact([
          'contact_id' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
          'name' => 'Clean Contact',
        ]),
      ], 0);
      $this->fail('Expected processPull() to throw because of the duplicate match');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('Not all records were saved', $e->getMessage());
    }

    foreach ([$rowA['id'], $rowB['id']] as $id) {
      $saved = \Civi\Api4\AccountContact::get(FALSE)->addWhere('id', '=', $id)->execute()->single();
      $this->assertNotEmpty($saved['error_data']);
      $this->assertStringContainsString('Duplicate records found', $saved['error_data']);
    }
    $clean = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff')
      ->execute();
    $this->assertCount(1, $clean);
    $this->assertEquals('Clean Contact', $clean->first()['accounts_display_name']);
  }

  public function testProcessPullHookVetoSkipsContact(): void {
    $this->vetoPull = TRUE;
    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([$this->getPulledXeroContact()], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute();
    $this->assertCount(0, $saved);
  }

  public function testProcessPullHookCanMutateParamsBeforeSave(): void {
    $this->mutateParamsHook = function (&$params) {
      $params['accounts_display_name'] = 'Overridden by hook';
    };
    $contact = new ContactPullTestable([]);
    $contact->callProcessPull([$this->getPulledXeroContact()], 0);

    $saved = \Civi\Api4\AccountContact::get(FALSE)
      ->addWhere('accounts_contact_id', '=', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->execute()
      ->single();
    $this->assertEquals('Overridden by hook', $saved['accounts_display_name']);
  }

}
