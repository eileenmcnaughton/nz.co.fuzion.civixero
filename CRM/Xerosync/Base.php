<?php

class CRM_Xerosync_Base {
  private static $singleton;
  private $_xero_key;
  private $_xero_secret;
  private $_xero_public_certificate;
  private $_xero_private_key;
  private $_plugin = 'xero';

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
      if(!empty($value)) {
        $value = $this->getSetting($var);
      }
      if($value != $this->{'_' .$var}) {
        $force = TRUE;
        $this->{'_' .$var} = $value;
      }
    }
    self::singleton($this->_xero_key, $this->_xero_secret, $this->_public_certificate, $this->_private_key, $force);
  }

  /**
   * @return CRM_Extension_System
   */
  public static function singleton($civixero_key = NULL, $civixero_secret = NULL, $publicCertificate = NULL, $privateKey = NULL, $force = FALSE) {
    if (!self::$singleton || $force) {
      self::$singleton = new CRM_Xerosync_Xero($civixero_key, $civixero_secret, $publicCertificate, $privateKey);
    }
    return self::$singleton;
  }

   /**
    * Get Xero Setting
    * @param String $var
    * @return Ambigous <multitype:, number, unknown>
    */
  function getSetting($var) {
    return(civicrm_api3('setting', 'getvalue', array('name' => $var, 'group' => 'Xero Settings')));
  }

  /**
   * Convert date to form expected by Xero
   * @param String $date date in mysql format (since it is coming through the api)
   * @return string formatted date
   */
  function formatDateForXero($date) {
    dpm(CRM_Utils_Date::mysqlToIso($date));
    return date("Y-m-d H:m:s", strtotime(CRM_Utils_Date::mysqlToIso($date)));
  }
}
