<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\InvoiceMappingTestable;
use Civi\MockConnector;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the push-time re-check of the accountsync queue-time settings
 * (CRM_Civixero_Invoice::isContributionEligibleForPush()).
 *
 * @group headless
 */
class InvoicePushEligibilityTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('org.civicrm.search_kit')
      ->install('nz.co.fuzion.accountsync')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    Civi::$statics['civixero_connector'] = new MockConnector();
    // Neutralise all three settings so each test enables only what it needs.
    Civi::settings()->set('account_sync_push_contribution_status', []);
    Civi::settings()->set('account_sync_contribution_day_zero', '');
    Civi::settings()->set('account_sync_skip_inv_by_pymt_processor', []);
    parent::setUp();
  }

  private function getInvoice(): InvoiceMappingTestable {
    return new InvoiceMappingTestable([]);
  }

  private function createContribution(string $status = 'Pending', string $receiveDate = '2024-03-15 10:00:00'): int {
    $contactID = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Push')
      ->addValue('last_name', 'Eligibility')
      ->execute()
      ->first()['id'];

    return Contribution::create(FALSE)
      ->addValue('contact_id', $contactID)
      ->addValue('financial_type_id.name', 'Donation')
      ->addValue('total_amount', 100.00)
      ->addValue('receive_date', $receiveDate)
      ->addValue('contribution_status_id:name', $status)
      ->execute()
      ->first()['id'];
  }

  private function getStatusID(string $status): int {
    return (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $status);
  }

  public function testEligibleWhenNoSettingsConfigured(): void {
    $contributionID = $this->createContribution('Completed');

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testIneligibleWhenStatusNotEnabled(): void {
    Civi::settings()->set('account_sync_push_contribution_status', [$this->getStatusID('Pending')]);
    $contributionID = $this->createContribution('Completed');

    $this->assertFalse($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testEligibleWhenStatusEnabled(): void {
    Civi::settings()->set('account_sync_push_contribution_status', [$this->getStatusID('Pending')]);
    $contributionID = $this->createContribution('Pending');

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testIneligibleWhenReceivedBeforeDayZero(): void {
    Civi::settings()->set('account_sync_contribution_day_zero', '2023-01-01');
    $contributionID = $this->createContribution('Pending', '2020-06-01 10:00:00');

    $this->assertFalse($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testEligibleWhenReceivedAfterDayZero(): void {
    Civi::settings()->set('account_sync_contribution_day_zero', '2023-01-01');
    $contributionID = $this->createContribution('Pending', '2024-06-01 10:00:00');

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testUnparseableDayZeroDoesNotBlockPush(): void {
    Civi::settings()->set('account_sync_contribution_day_zero', 'not-a-date');
    $contributionID = $this->createContribution('Pending', '2020-06-01 10:00:00');

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testIneligibleWhenPaidViaSkippedPaymentProcessor(): void {
    $processorID = $this->createDummyPaymentProcessor();
    Civi::settings()->set('account_sync_skip_inv_by_pymt_processor', [$processorID]);
    $contributionID = $this->createContribution('Pending');
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionID,
      'total_amount' => 100.00,
      'payment_processor_id' => $processorID,
      'is_send_contribution_notification' => 0,
    ]);

    $this->assertFalse($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testEligibleWhenPaidViaOtherPaymentProcessor(): void {
    $skippedProcessorID = $this->createDummyPaymentProcessor('skipped_dummy');
    $usedProcessorID = $this->createDummyPaymentProcessor('used_dummy');
    Civi::settings()->set('account_sync_skip_inv_by_pymt_processor', [$skippedProcessorID]);
    $contributionID = $this->createContribution('Pending');
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionID,
      'total_amount' => 100.00,
      'payment_processor_id' => $usedProcessorID,
      'is_send_contribution_notification' => 0,
    ]);

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => $contributionID]));
  }

  public function testEligibleWhenContributionMissing(): void {
    Civi::settings()->set('account_sync_push_contribution_status', [$this->getStatusID('Pending')]);

    // A deleted contribution is left to the existing error handling downstream.
    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush(['contribution_id' => 9999999]));
  }

  public function testEligibleWhenNoContributionID(): void {
    Civi::settings()->set('account_sync_push_contribution_status', [$this->getStatusID('Pending')]);

    $this->assertTrue($this->getInvoice()->callIsContributionEligibleForPush([]));
  }

  private function createDummyPaymentProcessor(string $name = 'eligibility_dummy'): int {
    return (int) PaymentProcessor::create(FALSE)
      ->addValue('name', $name)
      ->addValue('title', $name)
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('financial_account_id:name', 'Payment Processor Account')
      ->addValue('is_active', TRUE)
      ->execute()
      ->first()['id'];
  }

}
