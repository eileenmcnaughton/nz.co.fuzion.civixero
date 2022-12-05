<?php
namespace Civi\Xero;

use League\OAuth2\Client\Token\AccessTokenInterface;

interface ConnectorInterface {

  /**
   * Gets access token.
   *
   * If the current access token has expired get a new one from
   * Xero and store it.
   *
   * @return \League\OAuth2\Client\Token\AccessTokenInterface
   *
   * @throws \CRM_Core_Exception
   */
  public function getToken();
  public function getTenantID() : string;

}
