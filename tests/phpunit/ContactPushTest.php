<?php

use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\ContactTestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class ContactPushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;
  use ContactTestTrait;

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
    parent::setUp();
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
   *
   * (push() itself isn't exercised here as it requires a real Xero
   * connection to call getSingleton()->Contacts(); MockConnector only
   * stands in for the OAuth token exchange.)
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

}
