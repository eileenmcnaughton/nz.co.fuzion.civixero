<?php

namespace Civi;

use GuzzleHttp\ClientInterface;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\Configuration;

/**
 * Overrides CRM_Civixero_Invoice::getAccountingApiInstance() so the real SDK
 * (real request-building/serialization, real response deserialization) can
 * be exercised against a mocked Guzzle HTTP client instead of the network.
 * Mirrors ContactSdkPullTestable - see that class for why this lives outside
 * a *Test.php file.
 */
class InvoiceSdkPullTestable extends \CRM_Civixero_Invoice {

  public ClientInterface $mockClient;

  public function getAccountingApiInstance(): AccountingApi {
    $config = Configuration::getDefaultConfiguration()->setAccessToken($this->getAccessToken());
    return new AccountingApi($this->mockClient, $config);
  }

}
