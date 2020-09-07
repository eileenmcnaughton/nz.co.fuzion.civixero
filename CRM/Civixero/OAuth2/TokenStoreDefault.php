<?php

/**
 *
 * Default class for token storage.
 *
 * Stores token in extension settings.
 *
 */
class CRM_Civixero_OAuth2_TokenStoreDefault implements CRM_Civixero_OAuth2_TokenStoreInterface {

  protected $token;

  public function __construct($tokenData) {
    $this->token = new \League\OAuth2\Client\Token\AccessToken($tokenData);
  }

  /**
   * Save token to persistent storage.
   *
   * @todo remove this - this is overwritten by a connector-aware class or
   * make this connector-aware
   *
   * @param \League\OAuth2\Client\Token\AccessToken $token
   */
  public function save(\League\OAuth2\Client\Token\AccessToken $token) {
    Civi::settings()->set('xero_access_token', $token->jsonSerialize());
  }

  /**
   * Fetch token from persistent storage.
   *
   * @return \League\OAuth2\Client\Token\AccessToken
   */
  public function fetch() {
    return $this->token;
  }

}
