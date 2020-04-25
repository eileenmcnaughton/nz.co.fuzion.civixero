<?php
/**
 *
 * @file
 * Implementation of OAuth 2 Provider for Xero.
 *
 */
use League\OAuth2\Client\Provider\GenericProvider;

/**
 */
class CRM_Civixero_OAuth2_Provider_Xero extends \League\OAuth2\Client\Provider\GenericProvider {
  
  /**
   *
   * @var string
   */
  private $tenantID = null;
  
  /**
   *
   * @var string
   */
  private $authorizeURL = 'https://login.xero.com/identity/connect/authorize';
  
  /**
   *
   * @var string
   */
  private $tokenURL = 'https://identity.xero.com/connect/token';
  
  /**
   *
   * @var string
   */
  private $resourceOwnerURL = 'https://api.xero.com/api.xro/2.0/Organisation';
  
  /**
   *
   * @var string
   */
  private $connectionsURL = 'https://api.xero.com/connections';
  
  /**
   *
   * @var string
   */
  private $urlBaseAuthorize;
  
  /**
   *
   * @var array
   */
  private $defaultScopes = [
    // This may need revising, depending on the operations being performed.
    'offline_access',
    'accounting.settings',
    'accounting.transactions',
    'accounting.contacts',
    'accounting.journals.read',
    'accounting.reports.read'
  ];
  
  /**
   *
   * {@inheritdoc}
   * @see \League\OAuth2\Client\Provider\GenericProvider::getDefaultScopes()
   */
  public function getDefaultScopes() {
    return is_array($this->defaultScopes) ? implode($this->getScopeSeparator(), $this->defaultScopes) : $this->defaultScopes;
  }
  
  /**
   *
   * {@inheritdoc}
   * @see \League\OAuth2\Client\Provider\GenericProvider::getScopeSeparator()
   */
  public function getScopeSeparator() {
    return ' ';
  }
  
  /**
   *
   * @param array $options
   *        If being used for authorization then options should include:
   *        - redirectUri
   *        
   * @param array $collaborators
   */
  public function __construct($options = [], $collaborators = []) {
    $options = array_merge($options, [
      'urlAuthorize' => $this->authorizeURL,
      'urlAccessToken' => $this->tokenURL,
      'urlResourceOwnerDetails' => $this->resourceOwnerURL,
      'scopes' => $this->getDefaultScopes()
    ]);
    
    parent::__construct($options, $collaborators);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getAuthorizationUrl(Array $options = []) {
    $options = array_merge([
      'scopes' => $this->defaultScopes
    ], $options);
    return parent::getAuthorizationUrl($options);
  }
  
  /**
   * Get headers for authenticated request to Xero.
   *
   * @param mixed $token
   */
  protected function getAuthorizationHeaders($token = null) {
    // Bearer Authorization headers.
    $headers = parent::getAuthorizationHeaders($token);
    // Xero also requires tenant id for resource api requests.
    if ($this->tenantID) {
      $headers['Xero-tenant-id'] = $this->tenantID;
    }
    return $headers;
  }
  
  public function getTenantID($access_token) {
    return $this->tenantID ? $this->tenantID : $this->getConnectedTenantID($access_token);
  }
  
  /**
   * Gets the Tenant ID for the connected tenant.
   *
   * @param string $access_token
   * @return string|NULL
   */
  public function getConnectedTenantID($access_token) {
    $options['headers'] = [
      'Content-Type' => 'application/json'
    ];
    $request = $this->createRequest('get', $this->connectionsURL, $access_token, $options);
    $response = $this->getResponse($request);
    $data = $this->parseResponse($response);
    if ($response->getStatusCode() == 200) {
      $connection = reset($data);
      return !empty($connection['tenantId']) ? $connection['tenantId'] : NULL;
    }
  }
  
}