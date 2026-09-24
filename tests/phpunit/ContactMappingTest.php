<?php

use Civi\ContactMappingTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for CRM_Civixero_Contact::mapToAccounts() - the
 * CiviCRM-array -> Xero-shaped-array mapping used by push(). Pins down
 * current behaviour ahead of the Xero-SDK migration (which only touches
 * pushToXero(), not this method).
 *
 * @group headless
 */
class ContactMappingTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Set to veto (return FALSE) via hook_civicrm_accountPushAlterMapped().
   *
   * @var bool
   */
  public bool $vetoPush = FALSE;

  /**
   * Set to have hook_civicrm_accountPushAlterMapped() add a field to the
   * mapped array before it's returned.
   *
   * @var bool
   */
  public bool $mutateViaHook = FALSE;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    $this->vetoPush = FALSE;
    $this->mutateViaHook = FALSE;
    parent::setUp();
  }

  public function hook_civicrm_accountPushAlterMapped($entity, &$data, &$save, &$params) {
    if ($this->vetoPush) {
      $save = FALSE;
    }
    if ($this->mutateViaHook) {
      $params['Custom'] = 'added by hook';
    }
  }

  private function getBaseContact(array $overrides = []): array {
    return $overrides + [
      'id' => 123,
      'display_name' => 'Jane Doe',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ];
  }

  public function testMapsNameFieldsAndContactNumber(): void {
    $contact = new ContactMappingTestable([]);
    $mapped = $contact->callMapToAccounts($this->getBaseContact(), NULL);

    $this->assertIsArray($mapped);
    $this->assertEquals('Jane Doe', $mapped['Name']);
    $this->assertEquals('Jane', $mapped['FirstName']);
    $this->assertEquals('Doe', $mapped['LastName']);
    $this->assertEquals(123, $mapped['ContactNumber']);
    $this->assertArrayNotHasKey('ContactID', $mapped);
    $this->assertEquals('', $mapped['EmailAddress']);
  }

  public function testSetsContactIdOnlyWhenXeroUuidPassed(): void {
    $contact = new ContactMappingTestable([]);

    $withoutUuid = $contact->callMapToAccounts($this->getBaseContact(), NULL);
    $this->assertArrayNotHasKey('ContactID', $withoutUuid);

    $withUuid = $contact->callMapToAccounts($this->getBaseContact(), 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $withUuid['ContactID']);
  }

  public function testTruncatesLongDisplayNamePreservingIdSuffix(): void {
    $contact = new ContactMappingTestable([]);
    $longName = str_repeat('x', 300);

    $mapped = $contact->callMapToAccounts($this->getBaseContact(['display_name' => $longName]), NULL);

    $name = $mapped['Name'];
    $this->assertLessThanOrEqual(255, strlen($name));
    // The ' - <id>' suffix must survive truncation - only the display-name
    // portion is cut down.
    $this->assertStringEndsWith(' - 123', $name);
  }

  public function testValidEmailIsPassedThrough(): void {
    $contact = new ContactMappingTestable([]);
    $mapped = $contact->callMapToAccounts($this->getBaseContact(['email' => 'jane@example.com']), NULL);
    $this->assertEquals('jane@example.com', $mapped['EmailAddress']);
  }

  public function testInvalidEmailIsDroppedNotVetoed(): void {
    $contact = new ContactMappingTestable([]);
    $mapped = $contact->callMapToAccounts($this->getBaseContact(['email' => 'not-an-email']), NULL);
    // Invalid email doesn't stop the push - it's logged and pushed without one.
    $this->assertNotFalse($mapped);
    $this->assertEquals('', $mapped['EmailAddress']);
  }

  public function testPhoneOnlyIncludedWhenSet(): void {
    $contact = new ContactMappingTestable([]);

    $withoutPhone = $contact->callMapToAccounts($this->getBaseContact(), NULL);
    $this->assertArrayNotHasKey('Phones', $withoutPhone);

    $withPhone = $contact->callMapToAccounts($this->getBaseContact(['phone' => '0123456789']), NULL);
    $this->assertEquals('0123456789', $withPhone['Phones']['Phone']['PhoneNumber']);
    $this->assertEquals('DEFAULT', $withPhone['Phones']['Phone']['PhoneType']);
  }

  public function testAddressOnlyIncludedWhenAnAddressFieldIsSet(): void {
    $contact = new ContactMappingTestable([]);

    $withoutAddress = $contact->callMapToAccounts($this->getBaseContact(), NULL);
    $this->assertArrayNotHasKey('Addresses', $withoutAddress);

    $withAddress = $contact->callMapToAccounts($this->getBaseContact([
      'street_address' => '123 Main St',
      'city' => 'Wellington',
      'postal_code' => '6011',
      'country' => 'New Zealand',
      'state_province_name' => 'Wellington',
    ]), NULL);
    $address = $withAddress['Addresses']['Address'][0];
    $this->assertEquals('123 Main St', $address['AddressLine1']);
    $this->assertEquals('Wellington', $address['City']);
    $this->assertEquals('6011', $address['PostalCode']);
    $this->assertEquals('New Zealand', $address['Country']);
    $this->assertEquals('Wellington', $address['Region']);
    $this->assertEquals('POBOX', $address['AddressType']);
  }

  public function testHookVetoReturnsFalse(): void {
    $this->vetoPush = TRUE;
    $contact = new ContactMappingTestable([]);
    $mapped = $contact->callMapToAccounts($this->getBaseContact(), NULL);
    $this->assertFalse($mapped);
  }

  public function testHookCanMutateMappedArrayBeforeReturn(): void {
    $this->mutateViaHook = TRUE;
    $contact = new ContactMappingTestable([]);
    $mapped = $contact->callMapToAccounts($this->getBaseContact(), NULL);
    $this->assertEquals('added by hook', $mapped['Custom']);
  }

}
