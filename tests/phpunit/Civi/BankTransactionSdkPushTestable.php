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

  public function callPushToXero($accountsInvoice, $connector_id) {
    return $this->pushToXero($accountsInvoice, $connector_id);
  }

}
