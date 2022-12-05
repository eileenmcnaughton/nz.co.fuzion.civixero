<?php

namespace Civi;

use Civi\Xero\ConnectorInterface;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Mock connector for OAuth.
 */
class MockConnector implements ConnectorInterface {

  public function getToken(): AccessToken {
    return new AccessToken(['access_token' => TRUE]);
  }

  public function getTenantID(): string {
    return 'mock';
  }

}
