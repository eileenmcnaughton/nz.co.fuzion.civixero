<?php

use Civi\Api4\Job;
use Civi\Api4\Setting;
use CRM_Civixero_ExtensionUtil as E;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;

/**
 *
 * Controls page for handling OAuth 2.0 Authorization with Xero.
 *
 * TODO: Refactor reusable functionality into a separate class.
 *
 * @noinspection PhpUnused
 */
class CRM_Civixero_Form_XeroAuthorize extends CRM_Core_Form {

  private $clientID;

  private $clientSecret;

  private $hasValidTokens = FALSE;

  /**
   * @var CRM_Civixero_OAuth2_Provider_Xero
   */
  public $provider;

  /**
   * @var int
   */
  private $connectorID;

  /**
   * @var \CRM_Civixero_Settings
   */
  private $settings;

  /**
   * @var array
   */
  private $accessTokenData;

  /**
   * @throws \CRM_Core_Exception
   */
  public function preProcess(): void {
    // Note: Setting defaultValue = 0 doesn't work for retrieve (it gets set to NULL)
    $this->connectorID = CRM_Utils_Request::retrieveValue('connector_id', 'Integer') ?? 0;
    $this->settings = new CRM_Civixero_Settings($this->connectorID);
    $this->clientID = $this->settings->get('xero_client_id');
    $this->clientSecret = $this->settings->get('xero_client_secret');
    $this->accessTokenData = $this->settings->get('xero_access_token');
    $redirectURL = CRM_Utils_System::url('civicrm/xero/authorize',
      $this->connectorID > 0 ? ['connector_id' => $this->connectorID] : NULL,
      TRUE,
      NULL,
      FALSE,
      FALSE,
      TRUE
    );

    $this->provider = new CRM_Civixero_OAuth2_Provider_Xero([
      'clientId' => $this->clientID,
      'clientSecret' => $this->clientSecret,
      'redirectUri' => $redirectURL,
      ]
    );
    if ((empty($this->clientID) || empty($this->clientSecret))
      && (empty($this->getSubmittedValue('xero_client_id')) || empty($this->getSubmittedValue('xero_client_secret')))) {
      // Client ID / Client Secret not configured.
      return;
    }

    $civiXeroOAuth = CRM_Civixero_OAuth2_Xero::singleton(
      $this->connectorID,
      $this->clientID,
      $this->clientSecret,
      $this->settings->get('xero_tenant_id'),
      $this->accessTokenData
    );
    try {
      $accessToken = $civiXeroOAuth->getToken();

      // We may or may not have valid tokens at this point.
      // If we have a refresh token, test it by getting a new access token
      // and use them to get the tenant ID.
      if ($accessToken->getRefreshToken()) {
        $tenantID = $this->provider->getConnectedTenantID($accessToken->getToken());
        if ($tenantID) {
          $this->settings->save('xero_tenant_id', $tenantID);
          $this->hasValidTokens = TRUE;
        }
      }
    }
    catch (CRM_Core_Exception $e) {
      // Token not yet configured.
    }
    catch (IdentityProviderException $e) {
      // Expected invalid_grant. Continue to let user authorize.
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function processAuthCode(): void {
    // Have we been redirected back from an authorization?
    $code = CRM_Utils_Request::retrieveValue('code', 'String', '', FALSE, 'GET');
    $state = CRM_Utils_Request::retrieveValue('state', 'String', '', FALSE, 'GET');
    if ($code) {
      // Check state to mitigate against CSRF attacks.
      if ($state !== $this->getOauth2State()) {
        throw new CRM_Core_Exception('Invalid state.');
      }

      // Try to get an access token using the authorization code grant.
      $token = $this->provider->getAccessToken('authorization_code', [
        'code' => $code
      ]);
      // The refresh token and tenant_id
      // are required to get new access tokens without
      // needing the user to authorize again.
      $refresh_token = $token->getRefreshToken();
      $access_token = $token->getToken();
      $success = FALSE;
      if ($access_token && $refresh_token) {
        // The tenant_id is also required.
        $tenant_id = $this->provider->getConnectedTenantID($access_token);
        if ($tenant_id) {
          // Save to Settings.
          $this->settings->saveToken($token);
          $this->settings->save('xero_tenant_id', $tenant_id);
         // Signal success.
          $success = TRUE;
          CRM_Core_Session::setStatus(E::ts('Xero Authorization Successful'));
          // Redirect to clear stale $_GET params.
          CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/xero/authorize'));
        }
      }
      if (!$success) {
        CRM_Core_Session::setStatus(E::ts('Xero Authorization Not Successful, try again.'));
        \Civi::log()->error('XeroAuthorization Error: ' . json_encode($token->jsonSerialize()));
      }
    }
  }

  /**
   * Gets the URL to authorize with Xero.
   *
   * @return string
   */
  public function getAuthURL(): string {
    $options = [
      'state' => $this->getOauth2State(), // If empty, the provider will generate one.
    ];
    $url = $this->provider->getAuthorizationUrl($options);
    // The state is used to verify the response.
    // Store the state generated by the OAuth provider object.
    $this->setOauth2State($this->provider->getState());
    return $url;
  }

  /**
   * Gets the state used during  authorization.
   *
   * @return string
   */
  protected function getOauth2State(): ?string {
    return CRM_Core_Session::singleton()->get('oauth2state', 'xero');
  }

  /**
   * Stores the state used during authorization.
   *
   * @param ?string $state
   */
  protected function setOauth2State(?string $state = NULL): void {
    CRM_Core_Session::singleton()->set('oauth2state', $state, 'xero');
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    $fields = Setting::getFields(FALSE)
      ->setLoadOptions(TRUE)
      ->addWhere('name', 'IN', ['xero_client_id', 'xero_client_secret'])
      ->execute();
    foreach ($fields as $field) {
        $this->add($field['html_type'], $field['name'], $field['title'], $field['html_attributes'] ?? [], TRUE);
    }

    $buttons[] = [
      'type' => 'submit',
      'name' => E::ts('Save'),
      'isDefault' => TRUE,
    ];

    $accessTokenExpired = ($this->accessTokenData['expires'] < time());
    $this->assign('accesstoken_expiry_date', $this->accessTokenData['expires']);
    $this->assign('accesstoken_expired', $accessTokenExpired);

    //Check if we have returned from authorization and process data.
    // Do we have a client id
    if (empty($this->clientID) || empty($this->clientSecret)) {
      // Set status
      $statusMessage = 'You need to configure both Client ID and Client Secret.';
      $statusIcon = CRM_Core_Page::crmIcon('fa-exclamation-triangle');
    }
    else {
      $this->processAuthCode();
      if ($this->hasValidTokens) {
        $statusMessage = E::ts('CiviCRM can connect to Xero. You do not need to authorize again at this point.');
        $statusIcon = CRM_Core_Page::crmIcon('fa-check');
      }
      else {
        $statusMessage = ($accessTokenExpired ? E::ts('You need to Re-authorize with Xero by clicking the button below.') : E::ts('You need to Authorize with Xero by clicking the button below.'));
        $statusIcon = CRM_Core_Page::crmIcon('fa-exclamation-triangle');
        $buttons[] = [
          'type' => 'next',
          'name' => E::ts('Authorize with Xero'),
          'icon' => 'fa-lock',
          'subname' => 'auth',
        ];
      }
    }
    $this->assign('statusMessage', $statusMessage);
    $this->assign('statusIcon', $statusIcon);

    $xeroJobs = Job::get(FALSE)
      ->addWhere('parameters', 'LIKE', '%plugin=xero%')
      ->execute();
    foreach ($xeroJobs as $xeroJob) {
      $jobs[$xeroJob['description']] = [
        'active' => $xeroJob['is_active'],
      ];
    }
    $this->assign('xeroJobs', $jobs ?? []);
    $this->addButtons($buttons);
  }

  public function setDefaultValues(): array {
    $defaults['xero_client_id'] = $this->settings->get('xero_client_id') ?? '';
    $defaults['xero_client_secret'] = $this->settings->get('xero_client_secret') ?? '';
    return $defaults;
  }

  /**
   * Post process form.
   *
   * @throws \CRM_Core_Exception
   */
  public function postProcess(): void {
    if ($this->getSubmitValue('_qf_XeroAuthorize_next') !== NULL) {
      $action = 'authorize';
    }
    elseif ($this->getSubmitValue('_qf_XeroAuthorize_submit') !== NULL) {
      $action = 'save';
    }
    else {
      throw new CRM_Core_Exception('no idea what you are trying to do....');
    }

    // Check / update clientID/Secret
    $authChanged = FALSE;
    $newXeroClientID = $this->getSubmittedValue('xero_client_id');
    $newXeroClientSecret = $this->getSubmittedValue('xero_client_secret');
    $xeroClientID = $this->settings->get('xero_client_id');
    $xeroClientSecret = $this->settings->get('xero_client_secret');

    if ($xeroClientID !== $newXeroClientID) {
      $this->settings->save('xero_client_id', $newXeroClientID);
      $this->clientID = $newXeroClientID;
      $authChanged = TRUE;
    }
    if ($xeroClientSecret !== $newXeroClientSecret) {
      $this->settings->save('xero_client_secret', $newXeroClientSecret);
      $this->clientSecret = $newXeroClientSecret;
      $authChanged = TRUE;
    }

    if ($authChanged) {
      $this->settings->save('xero_tenant_id', '');
      $this->settings->save('xero_access_token_access_token', '');
      $this->settings->save('xero_access_token_expires', '');
    }

    if ($action === 'authorize') {
      $url = $this->getAuthURL();
      CRM_Utils_System::redirect($url);
    }
  }

}
