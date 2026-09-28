<?php

namespace Civi;

use GuzzleHttp\ClientInterface;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\Configuration;

/**
 * Overrides CRM_Civixero_Contact::getAccountingApiInstance() so the real SDK
 * (real request-building/serialization, real response deserialization) can
 * be exercised against a mocked Guzzle HTTP client instead of the network.
 * See InvoiceMappingTestable for why this lives outside a *Test.php file.
 */
class ContactSdkPushTestable extends \CRM_Civixero_Contact {

  public ClientInterface $mockClient;

  public function getAccountingApiInstance(): AccountingApi {
    $config = Configuration::getDefaultConfiguration()->setAccessToken($this->getAccessToken());
    return new AccountingApi($this->mockClient, $config);
  }

  public function callPushBatchToXero(array $mappedContacts): array {
    return $this->pushBatchToXero($mappedContacts);
  }

  /**
   * Push one contact, throwing the exception it failed with.
   */
  public function callPushToXero(array $mappedContact) {
    $result = $this->pushBatchToXero([$mappedContact])[0];
    if ($result instanceof \Exception) {
      throw $result;
    }
    return $result;
  }

}
