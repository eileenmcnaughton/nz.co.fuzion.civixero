<?php

namespace Civi;

use GuzzleHttp\ClientInterface;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\Configuration;

/**
 * Overrides CRM_Civixero_Invoice::getAccountingApiInstance() so the real SDK
 * (real request-building/serialization, real response deserialization) can
 * be exercised against a mocked Guzzle HTTP client instead of the network.
 * See InvoiceMappingTestable for why this lives outside a *Test.php file.
 */
class InvoiceSdkPushTestable extends \CRM_Civixero_Invoice {

  public ClientInterface $mockClient;

  public function getAccountingApiInstance(): AccountingApi {
    $config = Configuration::getDefaultConfiguration()->setAccessToken($this->getAccessToken());
    return new AccountingApi($this->mockClient, $config);
  }

  public function callPushBatchToXero(array $mappedRecords): array {
    return $this->pushBatchToXero($mappedRecords);
  }

  /**
   * Push one record, throwing the exception it failed with.
   */
  public function callPushToXero($accountsInvoice) {
    $result = $this->pushBatchToXero([$accountsInvoice])[0];
    if ($result instanceof \Exception) {
      throw $result;
    }
    return $result;
  }

}
