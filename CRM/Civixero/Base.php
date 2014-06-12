<?php

class CRM_Civixero_Base {
  private static $singleton;
  private $_xero_key;
  private $_xero_secret;
  private $_xero_public_certificate;
  private $_xero_private_key;
  protected $_plugin = 'xero';

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
      if(empty($value)) {
        $value = $this->getSetting($var);
      }
      if($value != $this->{'_' .$var}) {
        $force = TRUE;
        $this->{'_' .$var} = $value;
      }
      if(empty($value)) {
        throw new CRM_Core_Exception($var . ts(' has not been set'));
      }
    }
    $this->singleton($this->_xero_key, $this->_xero_secret, $this->_xero_public_certificate, $this->_xero_private_key, $force);
  }

  /**
   * @param $civixero_key
   * @param $civixero_secret
   * @param $publicCertificate
   * @param $privateKey
   * @param bool $force
   *
   * @return CRM_Extension_System
   */
  protected function singleton($civixero_key, $civixero_secret, $publicCertificate, $privateKey, $force = FALSE) {
    if (!self::$singleton || $force) {
      require_once 'packages/Xero/Xero.php';
      self::$singleton = new Xero($civixero_key, $civixero_secret, $publicCertificate, $privateKey);
    }
    return self::$singleton;
  }

  function getSingleton() {
    return self::$singleton;
  }

  /**
  * Get Xero Setting
  * @param String $var
  * @return Ambigous <multitype:, number, unknown>
  */
  function getSetting($var) {
    return civicrm_api3('setting', 'getvalue', array('name' => $var, 'group' => 'Xero Settings'));
  }

  /**
   * Convert date to form expected by Xero
   * @param String $date date in mysql format (since it is coming through the api)
   * @return string formatted date
   */
  function formatDateForXero($date) {
    return date("Y-m-d H:m:s", strtotime(CRM_Utils_Date::mysqlToIso($date)));
  }

  /**
   * Validate Response from Xero
   *
   * Unfortunately our Xero class doesn't pass summariseErrors so we don't have that to use :-(
   *
   * http://developer.xero.com/documentation/getting-started/http-requests-and-responses/#post-put-creating-many
   * @param array $response Response From Xero
   * @return multitype:string |Ambigous <boolean, multitype:string >
   */
  function validateResponse($response) {
    $message = '';
    $errors  = array();

    // comes back as a string for oauth errors
    if (is_string($response)) {
      foreach (explode('&', $response) as $response_item) {
        $keyval   = explode('=', $response_item);
        $errors[$keyval[0]] = urldecode($keyval[1]);
      }
      return $errors;
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
        else { // single message - string
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
    return is_array($errors) ? $errors : false;
  }
}
