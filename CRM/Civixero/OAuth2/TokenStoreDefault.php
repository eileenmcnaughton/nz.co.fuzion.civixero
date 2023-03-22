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

  protected $token;

  protected $connectorID;

  /**
   * Get the CiviCRM AccountSync Connector ID
   * @return mixed
   */
  public function getConnectorID() {
    return $this->connectorID;
  }

  /**
   * Set the CiviCRM AccountSync Connector ID
   * @param int $connectorID
   *
   * @return void
   */
  public function setConnectorID(int $connectorID) {
    $this->connectorID = $connectorID;
  }

  public function __construct($tokenData) {
    $this->token = new AccessToken($tokenData);
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
  public function fetch() {
    return $this->token;
  }

}
