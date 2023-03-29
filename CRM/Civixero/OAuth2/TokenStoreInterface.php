<?php

use League\OAuth2\Client\Token\AccessToken;

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
  public function save(AccessToken $token);

  /**
   * Fetch token from persistent storage.
   *
   * @return League\OAuth2\Client\Token\AccessToken
   */
  public function fetch(): AccessToken;

  /**
   * Set the CiviCRM AccountSync Connector ID
   *
   * @param int $connectorID
   */
  public function setConnectorID(int $connectorID);

  /**
   * Get the CiviCRM AccountSync Connector ID
   *
   * @return int
   */
  public function getConnectorID(): int;

}
