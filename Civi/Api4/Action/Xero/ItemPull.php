<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action retrieves Items from Xero
 *
 */
class ItemPull extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * @var string
   */
  protected string $name = '';

  /**
   * @var string
   */
  protected string $code = '';

  /**
   *
   * Note that the result class is that of the annotation below, not the h
   * in the method (which must match the parent class)
   *
   * @var \Civi\Api4\Generic\Result $result
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $params = ['connector_id' => $this->connectorID];
    $xero = new \CRM_Civixero_Item($params);
    if (!empty($this->name)) {
      $filters['where'][] = 'Name=="' . $this->name . '"';
    }
    if (!empty($this->code)) {
      $filters['where'][] = 'Code=="' . $this->code . '"';
    }
    $items = $xero->pull($filters ?? []);
    $result->exchangeArray($items ?? []);
    return $result;
  }

}
