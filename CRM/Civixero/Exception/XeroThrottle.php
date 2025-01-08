<?php

/**
 * Created by PhpStorm.
 * User: eileen
 * Date: 8/12/2014
 * Time: 10:33 AM
 */
class CRM_Civixero_Exception_XeroThrottle extends Exception {
  protected ?int $retryAfter = NULL;

  public function __construct($message, $code = 0, $previous = NULL, $retryAfter = NULL) {

    $this->retryAfter = $retryAfter;
    parent::__construct($message, $code, $previous);
  }

  public function getRetryAfter(): int {
    return (int) $this->retryAfter;
  }

}
