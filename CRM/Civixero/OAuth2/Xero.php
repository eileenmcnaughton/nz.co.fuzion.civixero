<?php
/**
 * Manages OAuth tokens and requests with CiviCRM settings.
 *
 * todo: refactor using CRM_Civixero_OAuth2_Provider_Xero
 *
 *
 */
class CRM_Civixero_OAuth2_Xero {
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

  private $connectionsURL = 'https://api.xero.com/connections';

  private $tenantID;

  private $redirectURL;


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
  public static function singleton(
      $connector_id = 0,
      $client_id = '',
      $client_secret = '',
      $tenant_id = '',
      $accessToken = ''
    ) {
    if (empty(self::$_instances[$connector_id])) {
      self::$_instances[$connector_id] = new CRM_Civixero_OAuth2_Xero($accessToken, $client_id, $client_secret, $tenant_id);
    }
    return self::$_instances[$connector_id];
  }

  public function __construct(
      $accessToken,
      $client_id,
      $client_secret,
      $tenant_id
    ) {
    $this->store = new CRM_Civixero_OAuth2_TokenStoreDefault($accessToken);
    $this->tenantID = $tenant_id;
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
   * @return \League\OAuth2\Client\Token\AccessToken
   * @throws \CRM_Core_Exception
   */
  public function getToken() {
    $accessToken = $this->store->fetch();
    if (!$accessToken) {
      throw new CRM_Core_Exception('No token in store.');
    }
    if (!$accessToken->hasExpired()) {
      return $accessToken;
    }
    $newToken = $this->renewToken($accessToken);
    return $newToken;
  }

  public function getTenantID() {
    return $this->tenantID;
  }

  public function renewToken(\League\OAuth2\Client\Token\AccessToken $token) {
    $newToken = $this->provider->getAccessToken('refresh_token', [
      'refresh_token' => $token->getRefreshToken()
    ]);
    // todo check token before saving.
    // Try again if failed?
    if ($newToken) {
      $this->store->save($newToken);
      CRM_Core_Error::debug_var('CiviXeroDebug', 'Storing renewed token.');
      return $newToken;
    }
  }

}
