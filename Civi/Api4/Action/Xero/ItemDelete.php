<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action deletes a Xero Item
 *
 */
class ItemDelete extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * @var string The Xero Item ID
   *
   * @required
   */
  protected string $itemID = '';

  /**
   *
   * Note that the result class is that of the annotation below, not the h
   * in the method (which must match the parent class)
   *
   * @var \Civi\Api4\Generic\Result $result
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    if (empty($this->itemID)) {
      throw new \CRM_Core_Exception('itemID is required.');
    }
    $params = ['connector_id' => $this->connectorID];
    $xero = new \CRM_Civixero_Item($params);
    $item = $xero->deleteItem($this->itemID);
    $result->exchangeArray($item);
    return $result;
  }

}
