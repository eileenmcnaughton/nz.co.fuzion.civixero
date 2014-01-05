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
   * @return CRM_Extension_System
   */
  protected function singleton($civixero_key, $civixero_secret, $publicCertificate, $privateKey, $force = FALSE) {
    if (!$this->singleton || $force) {
      require_once 'packages/Xero/Xero.php';
      $this->singleton = new Xero($civixero_key, $civixero_secret, $publicCertificate, $privateKey);
    }
    return self::$singleton;
  }

  function getSingleton() {
    return $this->singleton;
  }
  /**
   */
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
}
