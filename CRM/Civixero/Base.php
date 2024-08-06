<?php

use Civi\API\Event\PrepareEvent;
use Civi\Xero\ConnectorInterface;
use League\OAuth2\Client\Token\AccessToken;
use XeroAPI\XeroPHP\Api\AccountingApi;

/**
 * Class CRM_Civixero_Base
 *
 * Base class for classes that interact with Xero using push and pull methods.
 */
class CRM_Civixero_Base {

  private static array $singleton = [];

  /**
   * @var \League\OAuth2\Client\Token\AccessToken
   */
  private AccessToken $_xero_access_token;

  private string $_xero_tenant_id;

  protected string $_plugin = 'xero';

  protected array $accounts_contact;

  /**
   * Connector ID.
   *
   * This will be 0 if nz.co.fuzion.connectors is not being used.
   *
   * @var int
   */
  protected $connector_id;

  /**
   * @var \CRM_Civixero_Settings
   */
  protected CRM_Civixero_Settings $settings;

  /**
   * @return \League\OAuth2\Client\Token\AccessToken
   */
  protected function getAccessToken(): AccessToken {
    return $this->_xero_access_token;
  }

  /**
   * @return string
   */
  protected function getTenantID(): string {
    return $this->_xero_tenant_id;
  }

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
    $this->settings = new CRM_Civixero_Settings($this->connector_id);
    $xeroConnect = $this->getXeroConnector($parameters);
    $this->_xero_access_token = $xeroConnect->getToken();
    $this->settings->saveToken($this->_xero_access_token);
    $this->_xero_tenant_id = $xeroConnect->getTenantID();
    $this->singleton($this->_xero_access_token->getToken(), $this->_xero_tenant_id, $this->connector_id, $force);
  }

  public function getAccountingApiInstance(): AccountingApi {
    // Configure OAuth2 access token for authorization: OAuth2
    $config = \XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken($this->getAccessToken());

    $apiInstance = new AccountingApi(
      new \GuzzleHttp\Client(),
      $config
    );

    return $apiInstance;
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
      $problem = str_replace('oauth_problem=', '', $responseParts[0] ?? NULL);
      if ($problem === 'oauth_problem=token_rejected') {
        throw new CRM_Core_Exception('Invalid credentials');
      }
      if ($problem === 'signature_invalid') {
        throw new CRM_Core_Exception('Invalid signature - your key may be invalid');
      }
      // @fixme: Shouldn't we actually check if the error is "rate limit exceeded" rather than just falling back to that?
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
   * Check if the api rate is exceeded, during api prepare.
   *
   * @param \Civi\API\Event\PrepareEvent $event
   *
   * @return void
   * @throws \CRM_Civixero_Exception_XeroThrottle
   */
  public static function checkApiRateExceeded(PrepareEvent $event): void {
    if ($event->getEntityName() === 'Civixero' && (
      substr($event->getActionName(), -4) === 'push'
      || substr($event->getActionName(), -4) === 'pull'
    )) {
      $rateLimitExceeded = \Civi::settings()->get('xero_oauth_rate_exceeded');
      if (!$rateLimitExceeded) {
        return;
      }
      // Wait for 1 hour if rate limit was exceeded and then retry
      if (strtotime('+1 hours', $rateLimitExceeded) > time()) {
        throw new CRM_Civixero_Exception_XeroThrottle('Rate limit was previously triggered. Try again in 1 hour');
      }
      self::resetApiRateLimitExceeded();
    }
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

  /**
   * @return array|mixed
   * @throws \CiviCRM_API3_Exception
   */
  protected function getSetting($setting) {
   return $this->settings->get($setting);
  }

}
