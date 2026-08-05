<?php

use Civi\InvoiceResponseHandlingTestable;
use Civi\MockConnector;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for savePushResponse()/validateResponse() -
 * the seam that stays load-bearing across the Xero-SDK migration.
 *
 * pushToXero() is being swapped from the legacy Xero package to the
 * xeroapi/xero-php-oauth2 SDK, but savePushResponse()/validateResponse()
 * are unchanged by that migration - they consume whatever pushToXero()
 * returns. These tests pin down that they correctly interpret the legacy
 * response shapes pushToXero() has always produced.
 *
 * @group headless
 */
class InvoiceResponseHandlingTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

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
    parent::setUp();
  }

  public function tearDown(): void {
    CRM_Civixero_Base::resetApiRateLimitExceeded();
    parent::tearDown();
  }

  private function getInvoice(): InvoiceResponseHandlingTestable {
    return new InvoiceResponseHandlingTestable([]);
  }

  private function createBaseAccountInvoiceRecord(): array {
    $result = $this->callAPISuccess('AccountInvoice', 'create', [
      'plugin' => 'xero',
      'connector_id' => 0,
      'accounts_needs_update' => 1,
    ]);
    return reset($result['values']);
  }

  private function getAccountInvoice(int $id): array {
    return $this->callAPISuccess('AccountInvoice', 'getsingle', ['id' => $id]);
  }

  public function testSavePushResponseFalseResultMarksNoUpdateNeededWithoutError(): void {
    $record = $this->createBaseAccountInvoiceRecord();

    $errors = $this->getInvoice()->callSavePushResponse(FALSE, $record);

    $this->assertEquals([], $errors);
    $saved = $this->getAccountInvoice($record['id']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testSavePushResponseInvoiceSuccessShapeUpdatesRecord(): void {
    $record = $this->createBaseAccountInvoiceRecord();
    $result = [
      'Invoices' => [
        'Invoice' => [
          'InvoiceID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
          'UpdatedDateUTC' => '2024-03-15 10:00:00',
          'Status' => 'AUTHORISED',
          'Contact' => ['ContactID' => 'should-be-stripped'],
          'LineItems' => ['LineItem' => ['should-be-stripped']],
        ],
      ],
    ];

    $errors = $this->getInvoice()->callSavePushResponse($result, $record);

    $this->assertEquals([], $errors);
    $saved = $this->getAccountInvoice($record['id']);
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $saved['accounts_invoice_id']);
    $this->assertEquals('2024-03-15 10:00:00', $saved['accounts_modified_date']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
    // savePushResponse() sets error_data to the *string* 'null' - but API3's
    // "magic null string" convention (any field value literally 'null' means
    // "set this to SQL NULL") means it's actually stored/returned as NULL,
    // not the string 'null'.
    $this->assertArrayNotHasKey('error_data', $saved);
    // AUTHORISED maps to the 'pending' accounts_status_id.
    $accountsData = json_decode($saved['accounts_data'], TRUE);
    $this->assertArrayNotHasKey('Contact', $accountsData);
    $this->assertArrayNotHasKey('LineItems', $accountsData);
  }

  public function testSavePushResponseBankTransactionSuccessShapeUpdatesRecord(): void {
    $record = $this->createBaseAccountInvoiceRecord();
    $result = [
      'BankTransactions' => [
        'BankTransaction' => [
          'BankTransactionID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
          'UpdatedDateUTC' => '2024-03-15 10:00:00',
          'Status' => 'AUTHORISED',
        ],
      ],
    ];

    $errors = $this->getInvoice()->callSavePushResponse($result, $record);

    $this->assertEquals([], $errors);
    $saved = $this->getAccountInvoice($record['id']);
    $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $saved['accounts_invoice_id']);
    $this->assertEquals('2024-03-15 10:00:00', $saved['accounts_modified_date']);
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testSavePushResponseLegacyValidationErrorShapeIsRecordedAsError(): void {
    $record = $this->createBaseAccountInvoiceRecord();
    $result = [
      'Elements' => [
        'DataContractBase' => [
          'ValidationErrors' => [
            ['Message' => 'Something else is wrong'],
          ],
        ],
      ],
    ];

    $errors = $this->getInvoice()->callSavePushResponse($result, $record);

    $this->assertEquals(['Something else is wrong'], $errors);
    $saved = $this->getAccountInvoice($record['id']);
    // Not a "not update candidate" message, so still queued for retry.
    $this->assertEquals(1, $saved['accounts_needs_update']);
    $this->assertEquals(json_encode($errors), $saved['error_data']);
  }

  /**
   * pushViaApi() (added by PR #215, the Xero-SDK migration) returns
   * ['ValidationErrors' => [...]] on a Xero validation failure - a
   * top-level key distinct from the legacy package's nested
   * Elements.DataContractBase.ValidationErrors shape. Without the fix in
   * validateResponse(), this fell through to the "success" branch and
   * crashed reading $result['Invoices']['Invoice']['UpdatedDateUTC'] from a
   * response that doesn't have it. See InvoiceSdkPushTest for pushViaApi()
   * actually producing this shape from a mocked Xero response.
   */
  public function testSavePushResponseRecognisesNewSdkTopLevelValidationErrorsShape(): void {
    $record = $this->createBaseAccountInvoiceRecord();
    $result = ['ValidationErrors' => ['Account code must be specified']];

    $errors = $this->getInvoice()->callSavePushResponse($result, $record);

    $this->assertEquals(['Account code must be specified'], $errors);
    $saved = $this->getAccountInvoice($record['id']);
    $this->assertEquals(1, $saved['accounts_needs_update']);
    $this->assertEquals(json_encode($errors), $saved['error_data']);
  }

  public function testSavePushResponseNotUpdateCandidateClearsNeedsUpdateFlag(): void {
    $record = $this->createBaseAccountInvoiceRecord();
    $result = [
      'Elements' => [
        'DataContractBase' => [
          'ValidationErrors' => [
            ['Message' => 'Invoice not of valid status for modification'],
          ],
        ],
      ],
    ];

    $this->getInvoice()->callSavePushResponse($result, $record);

    $saved = $this->getAccountInvoice($record['id']);
    // We can't ever update this one in Xero, so stop retrying it.
    $this->assertEquals(0, $saved['accounts_needs_update']);
  }

  public function testValidateResponseAccountCodeMandatoryShortCircuits(): void {
    $response = [
      'Elements' => [
        'DataContractBase' => [
          'ValidationErrors' => [
            [
              ['Message' => 'Account code must be specified'],
            ],
          ],
        ],
      ],
    ];
    $errors = $this->getInvoice()->callValidateResponse($response);
    $this->assertEquals(['You need to set up the account code'], $errors);
  }

  public function testValidateResponseSignatureInvalidThrows(): void {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Invalid signature - your key may be invalid');
    $this->getInvoice()->callValidateResponse('oauth_problem=signature_invalid');
  }

  /**
   * Characterizes actual (not intended) behaviour: validateResponse() strips
   * the 'oauth_problem=' prefix into $problem, then compares $problem against
   * the *unstripped* string 'oauth_problem=token_rejected' - a comparison
   * that can never be true. So a token_rejected response falls through to
   * the generic throttle branch instead of throwing 'Invalid credentials'.
   * This is a pre-existing bug independent of the SDK migration - flagging
   * it here so it isn't accidentally "fixed" as a side effect of swapping
   * pushToXero(), which would silently change this test's expected outcome.
   */
  public function testValidateResponseTokenRejectedActuallyFallsThroughToThrottle(): void {
    $this->expectException(CRM_Civixero_Exception_XeroThrottle::class);
    $this->getInvoice()->callValidateResponse('oauth_problem=token_rejected');
  }

  public function testValidateResponseUnknownOauthProblemThrowsThrottleAndSetsRateLimit(): void {
    $this->assertFalse((bool) Civi::settings()->get('xero_oauth_rate_exceeded'));

    try {
      $this->getInvoice()->callValidateResponse('oauth_problem=rate_limit_exceeded');
      $this->fail('Expected a CRM_Civixero_Exception_XeroThrottle to be thrown');
    }
    catch (CRM_Civixero_Exception_XeroThrottle $e) {
      $this->assertEquals('rate_limit_exceeded', $e->getMessage());
    }

    $this->assertNotEmpty(Civi::settings()->get('xero_oauth_rate_exceeded'));
  }

  public function testIsNotUpdateCandidateMatchesSubstring(): void {
    $invoice = $this->getInvoice();
    $this->assertTrue($invoice->callIsNotUpdateCandidate([
      'Some prefix: This document cannot be edited as it has a payment or credit note allocated to it.',
    ]));
    $this->assertFalse($invoice->callIsNotUpdateCandidate([
      'Some unrelated error',
    ]));
  }

}
