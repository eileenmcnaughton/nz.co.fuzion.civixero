<?php

use League\OAuth2\Client\Token\AccessToken;

/**
 *
 * Default class for token storage.
 *
 * Stores token in extension settings.
 *
 */
class CRM_Civixero_OAuth2_TokenStoreDefault implements CRM_Civixero_OAuth2_TokenStoreInterface {

  /**
   * @var \League\OAuth2\Client\Token\AccessToken
   */
  protected $token;

  /**
   * @var int
   */
  protected $connectorID;

  public function __construct($tokenData, $connectorID) {
    $this->token = new AccessToken($tokenData);
    $this->connectorID = $connectorID;
  }

  /**
   * Get the CiviCRM AccountSync Connector ID
   *
   * @return int
   */
  public function getConnectorID(): int {
    return $this->connectorID;
  }

  /**
   * Set the CiviCRM AccountSync Connector ID
   *
   * @param int $connectorID
   */
  public function setConnectorID(int $connectorID): void {
    $this->connectorID = $connectorID;
  }

  /**
   * Save token to persistent storage.
   *
   * @param \League\OAuth2\Client\Token\AccessToken $token
   */
  public function save(AccessToken $token): void {
    $settings = new CRM_Civixero_Settings($this->getConnectorID());
    $settings->saveToken($token);
    $this->token = $token;
  }

  /**
   * Fetch token from persistent storage.
   *
   * @return \League\OAuth2\Client\Token\AccessToken
   */
  public function fetch(): AccessToken {
    return $this->token;
  }

}
