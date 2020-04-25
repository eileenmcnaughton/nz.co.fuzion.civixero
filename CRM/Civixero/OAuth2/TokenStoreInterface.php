<?php

/**
 * 
 * Interface for token storage.
 *
 */
interface CRM_Civixero_OAuth2_TokenStoreInterface {
  
  /**
   * Save token to persistent storage.
   * 
   * @param \League\OAuth2\Client\Token\AccessToken $token
   */
  
  public function save(\League\OAuth2\Client\Token\AccessToken $token);
  
  /**
   * Fetch token from persistent storage.
   * 
   * @return League\OAuth2\Client\Token\AccessToken
   */
  public function fetch();
  
}