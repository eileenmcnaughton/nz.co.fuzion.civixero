<?php

namespace Civi;

/**
 * Overrides CRM_Civixero_Contact::pushBatchToXero() with a canned-response
 * queue, so push() can be exercised end-to-end without any network access.
 * See InvoiceMappingTestable for why this lives outside a *Test.php file.
 */
class ContactPushTestable extends \CRM_Civixero_Contact {

  /**
   * @var array
   *   Queue of ['result' => $value] or ['throw' => $exception], one per
   *   contact sent, or ['throwBatch' => $exception] to fail a whole request.
   *   A queued CRM_Civixero_Exception_XeroThrottle also fails the whole
   *   request, as a 429 does.
   */
  public array $xeroResponses = [];

  /**
   * @var array
   *   The contacts passed to each pushBatchToXero() call, in order.
   */
  public array $sentBatches = [];

  /**
   * @var array
   *   Every contact sent, across all batches.
   */
  public array $sentContacts = [];

  protected function pushBatchToXero(array $mappedContacts): array {
    $this->sentBatches[] = $mappedContacts;
    array_push($this->sentContacts, ...$mappedContacts);
    $next = $this->xeroResponses[0] ?? NULL;
    if (isset($next['throwBatch']) || ($next['throw'] ?? NULL) instanceof \CRM_Civixero_Exception_XeroThrottle) {
      array_shift($this->xeroResponses);
      throw $next['throwBatch'] ?? $next['throw'];
    }
    $results = [];
    foreach ($mappedContacts as $key => $mapped) {
      if ($this->xeroResponses === []) {
        throw new \LogicException('ContactPushTestable::pushBatchToXero() called with nothing queued');
      }
      $next = array_shift($this->xeroResponses);
      $results[$key] = $next['throw'] ?? $next['result'];
    }
    return $results;
  }

}
