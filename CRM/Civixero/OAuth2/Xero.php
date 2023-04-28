<?php

// This require once should not be needed - but need to figure out how to remove
// without breakage.
require_once __DIR__ . '/../../../Civi/Xero/ConnectorInterface.php';
use Civi\Xero\ConnectorInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Manages OAuth tokens and requests with CiviCRM settings.
 *
 * todo: refactor using CRM_Civixero_OAuth2_Provider_Xero
 *
 *
 */
class CRM_Civixero_OAuth2_Xero implements ConnectorInterface {
  private static $_instances = [];

  /**
   *
   * @var CRM_Civixero_OAuth2_TokenStoreInterface
   */
  private $store;

  /**
   * @var \League\OAuth2\Client\Provider\GenericProvider
   */
  private $provider;

  private $authorizeURL = 'https://login.xero.com/identity/connect/authorize';

  private $tokenURL = 'https://identity.xero.com/connect/token';

  private $resourceOwnerURL = 'https://api.xero.com/api.xro/2.0/Organisation';

  private $tenantID;

  /**
   * @var int The CiviCRM AccountSync connector ID
   */
  private $connectorID;

  private $redirectURL;

  /**
   * @return string
   */
  public function getTenantID(): string {
    return $this->tenantID;
  }

  /**
   * @return int
   */
  public function getConnectorID(): int {
    return $this->connectorID;
  }

  /**
   * Get Xero instance.
   *
   * @param int $connector_id
   *
   * @param string $client_id
   * @param string $client_secret
   * @param string $tenant_id
   * @param string $accessToken
   *
   * @return CRM_Civixero_OAuth2_Xero
   */
  public static function singleton($connector_id = 0, $client_id = '', $client_secret = '', $tenant_id = '', $accessToken = '') {
    if (empty(self::$_instances[$connector_id])) {
      self::$_instances[$connector_id] = new CRM_Civixero_OAuth2_Xero($accessToken, $client_id, $client_secret, $tenant_id, $connector_id);
    }
    return self::$_instances[$connector_id];
  }

  public function __construct($accessToken, $client_id, $client_secret, $tenant_id, $connector_id) {
    $this->store = new CRM_Civixero_OAuth2_TokenStoreDefault($accessToken, $connector_id);
    $this->tenantID = $tenant_id;
    $this->connectorID = $connector_id;
    $this->redirectURL = CRM_Utils_System::url('civicrm/xero/authorize',
        NULL,
        TRUE,
        NULL,
        FALSE,
        FALSE,
        TRUE
     );
    $this->provider = new \League\OAuth2\Client\Provider\GenericProvider([
      'clientId' => $client_id,
      'clientSecret' => $client_secret,
      'redirectUri' => $this->redirectURL,
      'urlAuthorize' => $this->authorizeURL,
      'urlAccessToken' => $this->tokenURL,
      'urlResourceOwnerDetails' => $this->resourceOwnerURL,
    ]);
  }

  /**
   * Gets access token.
   *
   * If the current access token has expired get a new one from
   * Xero and store it.
   *
   * @return \League\OAuth2\Client\Token\AccessTokenInterface
   * @throws \CRM_Core_Exception
   * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
   */
  public function getToken(): AccessTokenInterface {
    $accessToken = $this->store->fetch();
    if (!$accessToken) {
      throw new CRM_Core_Exception('No token in store.');
    }
    if ($accessToken->hasExpired()) {
      // \Civi::log()->debug('CiviXero: Access token expired: ' . json_encode($accessToken->jsonSerialize()));
      $accessToken = $this->provider->getAccessToken('refresh_token', [
        'refresh_token' => $accessToken->getRefreshToken()
      ]);
      // \Civi::log()->debug('CiviXero: New access token: ' . json_encode($accessToken->jsonSerialize()));
      $this->store->save($accessToken);
    }
    return $accessToken;
  }

}
