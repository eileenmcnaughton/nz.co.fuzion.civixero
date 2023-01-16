<?php

use Civi\Xero\ConnectorInterface;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Class CRM_Civixero_Base
 *
 * Base class for classes that interact with Xero using push and pull methods.
 */
class CRM_Civixero_Base {

  private static $singleton = [];

  private $_xero_access_token;

  private $_xero_tenant_id;

  protected $_plugin = 'xero';

  protected $accounts_contact;

  /**
   * Connector ID.
   *
   * This will be 0 if nz.co.fuzion.connectors is not being used.
   *
   * @var int
   */
  protected $connector_id;

  /**
   * Class constructor.
   *
   * @param array $parameters
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function __construct($parameters = []) {
    $force = FALSE;
    $this->connector_id = $parameters['connector_id'] ?? 0;

    $xeroConnect = $this->getXeroConnector($parameters);
    $this->_xero_access_token = $xeroConnect->getToken();
    $this->saveToken($this->_xero_access_token);
    $this->_xero_tenant_id = $xeroConnect->getTenantID();
    $this->singleton($this->_xero_access_token->getToken(), $this->_xero_tenant_id, $this->connector_id, $force);
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
   * @throws \CiviCRM_API3_Exception
   */
  protected function saveToken(AccessToken $token): void {
    if ($this->connector_id === 0) {
      Civi::settings()->set('xero_access_token_refresh_token', $token->getRefreshToken());
      Civi::settings()->set('xero_access_token_access_token', $token->getToken());
      Civi::settings()->set('xero_access_token_expires', $token->getExpires());
      Civi::settings()->set('xero_access_token', $token->jsonSerialize());
    }
    else {
      civicrm_api3('Connector', 'create', ['id' => $this->connector_id, 'field4' => serialize($token->jsonSerialize())]);
    }
  }

  /**
   * Get the contact that the connector account is associated with.
   *
   * This is the domain contact by default.
   *
   * The nz.co.fuzion.connectors extension is required to use more than one account.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getAccountsContact() {
    if (empty($this->accounts_contact)) {
      if (empty($this->connector_id)) {
        $this->accounts_contact = civicrm_api3('domain', 'getvalue', [
          'current_domain' => TRUE,
          'return' => 'contact_id',
        ]);
      }
      else {
        $this->accounts_contact = civicrm_api3('connector', 'getvalue', [
          'id' => $this->connector_id,
          'return' => 'contact_id',
        ]);
      }
    }
    return $this->accounts_contact;
  }

  /**
   * Set the accounts contact.
   *
   * The nz.co.fuzion.connectors extension is required to use more than one account.
   *
   * @param int $contact_id
   *   Accounts contact ID. This is recorded in the civicrm_financial_type table
   *   and in the civicrm_connector table.
   */
  protected function setAccountsContact($contact_id): void {
    $this->accounts_contact = $contact_id;
  }

  /**
   * Singleton function.
   *
   * @param string $token
   * @param string $tenant_id
   * @param int $connector_id
   * @param bool $force
   *
   * @return \CRM_Extension_System
   */
  protected function singleton($token, $tenant_id, $connector_id, $force = FALSE) {
    if (!isset(self::$singleton[$connector_id]) || $force) {
      require_once 'packages/Xero/Xero.php';
      self::$singleton[$connector_id] = new Xero($token, $tenant_id);
    }

    return self::$singleton[$connector_id];
  }

  /**
   * Get instance of Xero object for connecting with Xero.
   *
   * @param int $connector_id
   *   The connector ID that is being synced. Unless nz.co.fuzion.connectors is
   *   in play this will be 0.
   *
   * @return Xero
   */
  protected function getSingleton($connector_id) {
    $this->connector_id = $connector_id;
    return self::$singleton[$connector_id];
  }

  /**
   * Get Xero Setting.
   *
   * @param string $var
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  protected function getSetting(string $var) {
    if ($this->connector_id > 0) {
      static $connectors = [];
      if (empty($connectors[$this->connector_id])) {
        $connector = civicrm_api3('connector', 'getsingle', ['id' => $this->connector_id]);
        $connectors[$this->connector_id] = [
          'xero_client_id' => $connector['field1'],
          'xero_client_secret' => $connector['field2'],
          'xero_tenant_id' => $connector['field3'],
          'xero_access_token' => unserialize($connector['field4']),
          // @todo not yet configurable per selector.
          'xero_default_invoice_status' => 'SUBMITTED',
        ];
      }

      return $connectors[$this->connector_id][$var];
    }
    if ($var === 'xero_access_token') {
      $token = civicrm_api3('setting', 'getvalue', [
        'name' => 'xero_access_token',
        'group' => 'Xero Settings',
      ]);
      $oauthToken =
        civicrm_api3('setting', 'get', [
          'name' => 'xero_access_token_refresh_token',
          'group' => 'Xero OAuth Settings',
        ])['values'][CRM_Core_Config::domainID()];
      if (!empty($oauthToken['xero_access_token_refresh_token'])) {
        $token['refresh_token'] = $oauthToken['xero_access_token_refresh_token'];
      }
      if (!empty($oauthToken['xero_access_token_expires'])) {
        $token['expires'] = $oauthToken['xero_access_token_expires'];
      }
      $token['token_type'] = 'Bearer';
      return $token;
    }
    return civicrm_api3('setting', 'getvalue', [
      'name' => $var,
      'group' => 'Xero Settings',
    ]);
  }

  /**
   * Convert date to form expected by Xero.
   *
   * @param string $date date in mysql format (since it is coming through the api)
   *
   * @return string
   *   Formatted date
   */
  protected function formatDateForXero($date) {
    return date('Y-m-d H:m:s', strtotime(CRM_Utils_Date::mysqlToIso($date)));
  }

  /**
   * Validate Response from Xero.
   *
   * Unfortunately our Xero class doesn't pass summariseErrors so we don't have that to use :-(
   *
   * http://developer.xero.com/documentation/getting-started/http-requests-and-responses/#post-put-creating-many
   *
   * @param array|string $response Response From Xero
   *
   * @return array|bool
   * @throws \CRM_Civixero_Exception_XeroThrottle
   * @throws \CRM_Core_Exception
   */
  protected function validateResponse($response) {
    $message = '';
    $errors = [];
    // Comes back as a string for oauth errors.
    if (is_string($response)) {
      $responseParts = explode('&', urldecode($response));
      $problem = str_replace('oauth_problem=', '', CRM_Utils_Array::value(0, $responseParts));
      if ($problem === 'oauth_problem=token_rejected') {
        throw new CRM_Core_Exception('Invalid credentials');
      }
      if ($problem === 'signature_invalid') {
        throw new CRM_Core_Exception('Invalid signature - your key may be invalid');
      }
      Civi::log('civixero')->error('Xero Oauth rate exceeded: ' . $message);
      CRM_Civixero_Base::setApiRateLimitExceeded();
      throw new CRM_Civixero_Exception_XeroThrottle($problem);
    }
    if (!empty($response['ErrorNumber'])) {
      $errors[] = $response['Message'];
    }

    if (!empty($response['Elements']) && is_array($response['Elements']['DataContractBase']['ValidationErrors'])) {
      foreach ($response['Elements']['DataContractBase']['ValidationErrors'] as $key => $value) {
        // we have a situation where the validation errors are an array of errors
        // original code expected a string - not sure if / when that might happen
        // this is all a bit of a hackathon @ the moment
        if (is_array($value[0])) {
          foreach ($value as $errorMessage) {
            if (trim($errorMessage['Message']) === 'Account code must be specified') {
              return [
                'You need to set up the account code',
              ];
            }
            $message .= ' ' . $errorMessage['Message'];
          }
        }
        else {
          // Single message - string
          $message = $value['Message'];
        }
        switch (trim($message)) {
          case 'The Contact Name already exists. Please enter a different Contact Name.':
            $contact = $response['Elements']['DataContractBase']['Contact'];
            $message .= '<br>contact ID is ' . $contact['ContactNumber'];
            $message .= '<br>contact name is ' . $contact['Name'];
            $message .= '<br>contact email is ' . $contact['EmailAddress'];
            break;

          case 'The TaxType field is mandatory Account code must be specified':
            $message = 'Account code needs setting up';
        }
        $errors[] = $message;
      }
    }
    return is_array($errors) ? $errors : FALSE;
  }

  /**
   * @param array $parameters
   *
   * @return ConnectorInterface
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function getXeroConnector(array $parameters): ConnectorInterface {
    if (isset(Civi::$statics['civixero_connector'])) {
      // A bit of a hack to allow us to use a test specific connector.
      return Civi::$statics['civixero_connector'];
    }
    return CRM_Civixero_OAuth2_Xero::singleton(
      $this->connector_id,
      trim($parameters['xero_client_id'] ?? $this->getSetting('xero_client_id')),
      trim($parameters['xero_client_secret'] ?? $this->getSetting('xero_client_secret')),
      trim($parameters['xero_tenant_id'] ?? $this->getSetting('xero_tenant_id')),
      $parameters['xero_access_token'] ?? $this->getSetting('xero_access_token')
    );
  }

  /**
   * @param bool $throwException
   *  Deprecated parameter - should return true or false
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public static function isApiRateLimitExceeded($throwException = FALSE) {
    $rateLimitExceeded = Civi::settings()->get('xero_oauth_rate_exceeded');
    if (!$rateLimitExceeded) {
      return FALSE;
    }
    // Wait for 1 hour if rate limit was exceeded and then retry
    if (strtotime('+1 hours', $rateLimitExceeded) > time()) {
      if ($throwException) {
        throw new CRM_Core_Exception('Rate limit was previously triggered. Try again in 1 hour');
      }
      return TRUE;
    }
    self::resetApiRateLimitExceeded();
    return FALSE;
  }

  /**
   * @return void
   */
  public static function setApiRateLimitExceeded(): void {
    Civi::settings()->set('xero_oauth_rate_exceeded', time());
  }

  /**
   * @return void
   */
  public static function resetApiRateLimitExceeded(): void {
    Civi::settings()->set('xero_oauth_rate_exceeded', NULL);
  }

}
