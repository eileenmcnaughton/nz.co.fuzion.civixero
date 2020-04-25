<?php

/**
 * Class CRM_Civixero_Base
 *
 * Base class for classes that interact with Xero using push and pull methods.
 */
class CRM_Civixero_Base {

  private static $singleton;

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
   */
  public function __construct($parameters = []) {
    $force = FALSE;
    $this->connector_id = CRM_Utils_Array::value('connector_id', $parameters, 0);
    // Currently only default connection (without nz.co.fusion.connectors) is supported.
    if ($this->connector_id == 0) {
      $xeroConnect = CRM_Civixero_OAuth2_Xero::singleton();
      $this->_xero_access_token = $xeroConnect->getToken();
      $this->_xero_tenant_id = $xeroConnect->getTenantID();
    }
    else {
      // TODO: implement for connectors.
      throw new CRM_Core_Exception(
          "Currently only default Xero connection (without nz.co.fusion.connectors) is supported."
          );
    }
    $this->singleton($this->_xero_access_token, $this->_xero_tenant_id, $this->connector_id, $force);
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
  protected function setAccountsContact($contact_id) {
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
    if (!self::$singleton[$connector_id] || $force) {
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
   */
  protected function getSetting($var) {
    
    if ($this->connector_id > 0) {
      static $connectors = [];
      if (empty($connectors[$this->connector_id])) {
        $connector = civicrm_api3('connector', 'getsingle', ['id' => $this->connector_id]);
        $connectors[$this->connector_id] = [
          'xero_key' => $connector['field1'],
          'xero_secret' => $connector['field2'],
          'xero_public_certificate' => $connector['field3'],
          'xero_private_key' => $connector['field4'],
          // @todo not yet configurable per selector.
          'xero_default_invoice_status' => 'SUBMITTED',
        ];
      }
      return $connectors[$this->connector_id][$var];
    }
    else {
      return civicrm_api3('setting', 'getvalue', [
        'name' => $var,
        'group' => 'Xero Settings',
      ]);
    }
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
    return date("Y-m-d H:m:s", strtotime(CRM_Utils_Date::mysqlToIso($date)));
  }

  /**
   * Validate Response from Xero.
   *
   * Unfortunately our Xero class doesn't pass summariseErrors so we don't have that to use :-(
   *
   * http://developer.xero.com/documentation/getting-started/http-requests-and-responses/#post-put-creating-many
   *
   * @param array $response Response From Xero
   *
   * @return array|bool
   * @throws \CRM_Civixero_Exception_XeroThrottle
   * @throws \Exception
   */
  protected function validateResponse($response) {
    $message = '';
    $errors = [];
    // Comes back as a string for oauth errors.
    if (is_string($response)) {
      $responseParts = explode('&', urldecode($response));
      $problem = str_replace('oauth_problem=', '', CRM_Utils_Array::value(0, $responseParts));
      if ($problem == 'oauth_problem=token_rejected') {
        throw new Exception('Invalid credentials');
      }
      if ($problem == 'signature_invalid') {
        throw new Exception('Invalid signature - your key may be invalid');
      }
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
            if (trim($errorMessage['Message']) == 'Account code must be specified') {
              return [
                'You need to set up the account code',
              ];
            }
            $message .= " " . $errorMessage['Message'];
          }
        }
        else {
          // Single message - string
          $message = $value['Message'];
        }
        switch (trim($message)) {
          case "The Contact Name already exists. Please enter a different Contact Name.":
            $contact = $response['Elements']['DataContractBase']['Contact'];
            $message .= "<br>contact ID is " . $contact['ContactNumber'];
            $message .= "<br>contact name is " . $contact['Name'];
            $message .= "<br>contact email is " . $contact['EmailAddress'];
            break;

          case "The TaxType field is mandatory Account code must be specified":
            $message = "Account code needs setting up";
        }
        $errors[] = $message;
      }
    }
    return is_array($errors) ? $errors : FALSE;
  }

}
