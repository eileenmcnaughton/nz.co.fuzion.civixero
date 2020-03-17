<?php

/**
 * Created by PhpStorm.
 * User: eileen
 * Date: 8/12/2014
 * Time: 10:33 AM
 */
class CRM_Civixero_Exception_XeroThrottle extends Exception {

  private $id;

  /**
   * Class constructor.
   *
   * @param string $message
   */
  public function __construct($message) {
    parent::__construct(ts($message));
    CRM_Core_Error::debug_log_message('Oath rate exceeded: ' . $message);
  }

}
