<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action retrieves Items from Xero
 *
 */
class ContactPull extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * @var string
   */
  protected string $contactNumber = '';

  /**
   *
   * Note that the result class is that of the annotation below, not the h
   * in the method (which must match the parent class)
   *
   * @var \Civi\Api4\Generic\Result $result
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $params = ['connector_id' => $this->connectorID];
    $xero = new \CRM_Civixero_Contact($params);
    if (!empty($this->contactNumber)) {
      $filters['where'][] = 'ContactNumber=="' . $this->contactNumber . '"';
    }
    if (!empty($this->code)) {
      $filters['where'][] = 'Code=="' . $this->code . '"';
    }
    $items = $xero->newPull($filters ?? [], $this->contactNumber);
    $result->exchangeArray($items ?? []);
    return $result;
  }

}
