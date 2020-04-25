<?php

/**
 * 
 * Default class for token storage.
 * 
 * Stores token in extension settings.
 *
 */
class CRM_Civixero_OAuth2_TokenStoreDefault implements CRM_Civixero_OAuth2_TokenStoreInterface {
  
  /**
   * Save token to persistent storage.
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
    $tokenData = Civi::settings()->get('xero_access_token');
    return !empty($tokenData['access_token']) 
       ? new \League\OAuth2\Client\Token\AccessToken($tokenData)
    : NULL;
  }
  
}