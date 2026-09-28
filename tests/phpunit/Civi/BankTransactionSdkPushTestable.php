<?php

namespace Civi;

use GuzzleHttp\ClientInterface;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\Configuration;

/**
 * See InvoiceSdkPushTestable - same purpose, for CRM_Civixero_BankTransaction.
 */
class BankTransactionSdkPushTestable extends \CRM_Civixero_BankTransaction {

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
