<?php

use League\OAuth2\Client\Token\AccessToken;

class CRM_Civixero_Settings {

  /**
   * Connector ID.
   *
   * This will be 0 if nz.co.fuzion.connectors is not being used.
   *
   * @var int
   */
  protected $connectorID;

  public function __construct($connectorID) {
    $this->connectorID = $connectorID;
  }

  private function getConnectorID(): int {
    return $this->connectorID ?? 0;
  }

  /**
   * Save the token.
   *
   * The token is already saved - but by a non-connector aware class.
   *
   * Doing it here is a quick-for-now-fix
   *
   * @param \League\OAuth2\Client\Token\AccessToken $token
   *
   * @throws \CRM_Core_Exception
   */
  public function saveToken(AccessToken $token): void {
    if ($this->getConnectorID() === 0) {
      Civi::settings()->set('xero_access_token_refresh_token', $token->getRefreshToken());
      Civi::settings()->set('xero_access_token_access_token', $token->getToken());
      Civi::settings()->set('xero_access_token_expires', $token->getExpires());
    }
    else {
      civicrm_api3('Connector', 'create', ['id' => $this->getConnectorID(), 'field4' => serialize($token->jsonSerialize())]);
    }
  }

  /**
   * @param string $name
   * @param mixed $value
   *
   * @throws \CRM_Core_Exception
   */
  public function save(string $name, $value): void {
    if ($this->getConnectorID() > 0) {
      static $connectors = [];
      if (!empty($connectors[$this->getConnectorID()])) {
        unset($connectors[$this->getConnectorID()]);
      }
      $mapping = [
        'xero_client_id' => 'field1',
        'xero_client_secret' => 'field2',
        'xero_tenant_id' => 'field3',
        'xero_access_token' => 'field4',
      ];
      if (is_array($value)) {
        $value = serialize($value);
      }
      $params = ['id' => $this->getConnectorID(), $mapping[$name] => $value];
      civicrm_api3('Connector', 'create', $params);
    }
    else {
      Civi::settings()->set($name, $value);
    }
  }

  /**
   * Get Xero Setting.
   *
   * @param string $var
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public function get(string $var) {
    if ($this->getConnectorID() > 0) {
      static $connectors = [];
      if (empty($connectors[$this->getConnectorID()])) {
        $connector = civicrm_api3('connector', 'getsingle', ['id' => $this->getConnectorID()]);
        $connectors[$this->getConnectorID()] = [
          'xero_client_id' => $connector['field1'],
          'xero_client_secret' => $connector['field2'],
          'xero_tenant_id' => $connector['field3'],
          'xero_access_token' => unserialize($connector['field4']),
          // @todo not yet configurable per selector.
          'xero_default_invoice_status' => 'SUBMITTED',
        ];
      }

      return $connectors[$this->getConnectorID()][$var];
    }
    if ($var === 'xero_access_token') {
      return [
        'access_token' => \Civi::settings()->get('xero_access_token_access_token'),
        'refresh_token' => \Civi::settings()->get('xero_access_token_refresh_token'),
        'expires' => \Civi::settings()->get('xero_access_token_expires'),
        'token_type' => 'Bearer',
      ];
    }
    return \Civi::settings()->get($var);
  }
}
