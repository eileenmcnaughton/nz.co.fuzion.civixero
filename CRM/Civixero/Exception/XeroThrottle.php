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
    Civi::log('civixero')->error('Xero Oauth rate exceeded: ' . $message);
    CRM_Civixero_Base::setApiRateLimitExceeded();
  }

}
