<?php
/**
 * Class CRM_Civixero_Base
 *
 * Base class for classes that interact with Xero using push and pull methods.
 */
class CRM_Civixero_Base {
  private static $singleton;
  private $_xero_key;
  private $_xero_secret;
  private $_xero_public_certificate;
  private $_xero_private_key;
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
  public function __construct($parameters = array()) {
    $force = FALSE;
    $this->connector_id = CRM_Utils_Array::value('connector_id', $parameters, 0);
    $variables = array(
      'xero_key',
      'xero_secret',
      'xero_public_certificate',
      'xero_private_key',
    );
    foreach ($variables as $var) {
      $value = CRM_Utils_Array::value($var, $parameters);
      if (empty($value)) {
        $value = $this->getSetting($var);
      }
      if ($value != $this->{'_' . $var}) {
        $force = TRUE;
        $this->{'_' . $var} = $value;
      }
      if (empty($value)) {
        throw new CRM_Core_Exception($var . ts(' has not been set'));
      }
    }
    $this->singleton($this->_xero_key, $this->_xero_secret, $this->_xero_public_certificate, $this->_xero_private_key, $this->connector_id, $force);
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
        $this->accounts_contact = civicrm_api3('domain', 'getvalue', array(
          'current_domain' => TRUE,
          'return' => 'contact_id',
        ));
      }
      else {
        $this->accounts_contact = civicrm_api3('connector', 'getvalue', array(
          'id' => $this->connector_id,
          'return' => 'contact_id',
        ));
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
   * @param string $civixero_key
   * @param string $civixero_secret
   * @param string $publicCertificate
   * @param string $privateKey
   * @param int $connector_id
   * @param bool $force
   *
   * @return \CRM_Extension_System
   */
  protected function singleton($civixero_key, $civixero_secret, $publicCertificate, $privateKey, $connector_id, $force = FALSE) {
    if (!self::$singleton[$connector_id] || $force) {
      require_once 'packages/Xero/Xero.php';
      self::$singleton[$connector_id] = new Xero($civixero_key, $civixero_secret, $publicCertificate, $privateKey);
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
      static $connectors = array();
      if (empty($connectors[$this->connector_id])) {
        $connector = civicrm_api3('connector', 'getsingle', array('id' => $this->connector_id));
        $connectors[$this->connector_id] = array(
          'xero_key' => $connector['field1'],
          'xero_secret' => $connector['field2'],
          'xero_public_certificate' => $connector['field3'],
          'xero_private_key' => $connector['field4'],
          // @todo not yet configurable per selector.
          'xero_default_invoice_status' => 'SUBMITTED',
        );
      }
      return $connectors[$this->connector_id][$var];
    }
    else {
      return civicrm_api3('setting', 'getvalue', array(
        'name' => $var,
        'group' => 'Xero Settings',
      ));
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
    $errors  = array();
    // Comes back as a string for oauth errors.
    if (is_string($response)) {
      $responseParts = explode('&', urldecode($response));
      if (CRM_Utils_Array::value(0, $responseParts) == 'oauth_problem=token_rejected') {
        throw new Exception('Invalid credentials');
      }
      throw new CRM_Civixero_Exception_XeroThrottle($responseParts['oauth_problem']);
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
              return array(
                'You need to set up the account code',
              );
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
