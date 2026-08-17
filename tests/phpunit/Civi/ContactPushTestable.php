<?php

namespace Civi;

/**
 * Overrides CRM_Civixero_Contact::pushToXero() with a canned-response queue,
 * so push() can be exercised end-to-end without any network access -
 * regardless of whether pushToXero() itself talks to the legacy Xero
 * package or the new xeroapi/xero-php-oauth2 SDK. See InvoiceMappingTestable
 * for why this lives outside a *Test.php file.
 */
class ContactPushTestable extends \CRM_Civixero_Contact {

  /**
   * @var array
   *   Queue of either ['result' => $value] or ['throw' => $exception].
   */
  public array $pushToXeroQueue = [];

  /**
   * @var array
   *   The $accountsContact argument passed to pushToXero() on each call, in order.
   */
  public array $pushToXeroCalls = [];

  protected function pushToXero(array $accountsContact, $connector_id) {
    $this->pushToXeroCalls[] = $accountsContact;
    if ($this->pushToXeroQueue === []) {
      throw new \LogicException('ContactPushTestable::pushToXero() called with nothing queued');
    }
    $next = array_shift($this->pushToXeroQueue);
    if (array_key_exists('throw', $next)) {
      throw $next['throw'];
    }
    return $next['result'];
  }

}
