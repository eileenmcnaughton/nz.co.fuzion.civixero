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
   * Class constructor.
   *
   * @param array $parameters
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($parameters = array()) {
    $force = FALSE;
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
    $contact_id = !empty($parameters['accounts_contact_id']) ? $parameters['accounts_contact_id'] : $this->getAccountsContact();
    $this->singleton($this->_xero_key, $this->_xero_secret, $this->_xero_public_certificate, $this->_xero_private_key, $contact_id, $force);
  }

  /**
   * Get the contact that the account is associated with. This is the domain contact by default.
   *
   * We index the singleton instances by this in case we wish to load a different    * We index the singleton instances by this in case we wish to load a different
   * Xero instance (with different credentials).
   * The nz.co.fuzion.connectors extension is required to use more than one account.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getAccountsContact() {
    if (empty($this->accounts_contact)) {
      $this->accounts_contact = civicrm_api3('domain', 'getvalue', array('current_domain' => TRUE, 'return' => 'contact_id'));
    }
    return $this->accounts_contact;
  }

  /**
   * Set the accounts contact.
   *
   * We index the singleton instances by this in case we wish to load a different
   * Xero instance (with different credentials).
   * The nz.co.fuzion.connectors extension is required to use more than one account.
   *
   * @param $contact_id
   *
   * @return array
   */
  protected function setAccountsContact($contact_id) {
    $this->accounts_contact = $contact_id;
    if (empty(self::$singleton[$contact_id])) {
      try {
        $connector = civicrm_api3('Connector', 'get', array(
          'connector_type_id' => 'CiviXero',
          'contact_id' => $contact_id,
        ));
        echo "<pre>";
        print_r($connector);
        $this->singleton(
          $connector['field1'],
          $connector['field2'],
          $connector['field3'],
          $connector['field4'],
          $contact_id
        );
      }
      catch (CiviCRM_API3_Exception $e) {
        echo "<pre>";
        print_r($e);
        // What now? We'll just leave it untouched...
      }
    }
  }

  /**
   * Singleton function.
   *
   * @param $civixero_key
   * @param $civixero_secret
   * @param $publicCertificate
   * @param $privateKey
   * @param $contact_id
   * @param bool $force
   *
   * @return \CRM_Extension_System
   */
  protected function singleton($civixero_key, $civixero_secret, $publicCertificate, $privateKey, $contact_id, $force = FALSE) {
    if (!self::$singleton[$contact_id] || $force) {
      require_once 'packages/Xero/Xero.php';
      self::$singleton[$contact_id] = new Xero($civixero_key, $civixero_secret, $publicCertificate, $privateKey);
    }

    return self::$singleton[$contact_id];
  }

  /**
   * Get instance of Xero object for connecting with Xero.
   *
   * @param int $contact_id
   *   The contact ID that 'owns' this account. This is only really relevant with the
   *   nz.co.fuzion.connectors extension to store multiple credentials.
   *
   * @return Xero
   */
  protected function getSingleton($contact_id = NULL) {
    if (empty($contact_id)) {
      $contact_id = $this->getAccountsContact();
    }
    return self::$singleton[$contact_id];
  }

  /**
   * Get Xero Setting.
   *
   * @param string $var
   *
   * @return mixed
   */
  protected function getSetting($var) {
    return civicrm_api3('setting', 'getvalue', array('name' => $var, 'group' => 'Xero Settings'));
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
   */
  protected function validateResponse($response) {
    $message = '';
    $errors  = array();
    // Comes back as a string for oauth errors.
    if (is_string($response)) {
      $responseParts = explode('&', urldecode($response));
      throw new CRM_Civixero_Exception_XeroThrottle($responseParts['oauth_problem']);
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
                'You need to set up the account code'
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
