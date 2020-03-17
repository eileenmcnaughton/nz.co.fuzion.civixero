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
   * Get multiton instance.
   * 
   * @param CRM_Civixero_OAuth2_TokenStoreInterface $store
   * @param number $connector_id
   */
  public static function singleton(
      CRM_Civixero_OAuth2_TokenStoreInterface $store = NULL,
      $client_id = '',
      $client_secret = '',
      $tenant_id = '',
      $connector_id = 0
      ) {
    if (empty(self::$_instances[$connector_id])) {
      self::$_instances[$connector_id] = new CRM_Civixero_OAuth2_Xero($store); 
    }
    return self::$_instances[$connector_id];
  }
  
  public function __construct(
      CRM_Civixero_OAuth2_TokenStoreInterface $store = NULL,
      $client_id = '',
      $client_secret = '',
      $tenant_id = ''
      ) {
    if (!$store) {
      $store = new CRM_Civixero_OAuth2_TokenStoreDefault();
    }
    $this->store = $store;
    if (!$client_id) {
      $client_id = trim(Civi::settings()->get('xero_client_id'));
      $client_secret = trim(Civi::settings()->get('xero_client_secret'));
      
    }
    if (!$tenant_id) {
      $this->tenantID = Civi::settings()->get('xero_tenant_id');
    }
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
   */
  public function getToken() {
    $accessToken = $this->store->fetch();
    if (!$accessToken) {
      throw new CRM_Core_Exception('No token in store.');
    }
    if (!$accessToken->hasExpired()) {
      return $accessToken->getToken();
    }
    $newToken = $this->renewToken($accessToken);
    return $newToken->getToken();
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