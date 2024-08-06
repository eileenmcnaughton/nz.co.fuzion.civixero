<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action creates an Item in Xero
 *
 */
class ItemCreate extends \Civi\Api4\Generic\AbstractCreateAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * The item code (max length 30)
   * @var string
   * @required
   */
  protected string $code = '';

  /**
   * The item name (max length 50)
   * @var string
   */
  protected string $name = '';

  /**
   * Is this item available to be sold
   * @var bool
   */
  protected bool $is_sold = TRUE;

  /**
   * Is this item available to purchase
   * @var bool
   */
  protected bool $is_purchased = TRUE;

  /**
   * The item description (max length 4000)
   * @var string
   */
  protected string $description = '';

  /**
   * The item purchase description (max length 4000)
   * @var string
   */
  protected string $purchase_description = '';

  /**
   * Should be float but API4 doesn't support that as param (yet?)
   * @var string
   */
  protected string $purchase_unit_price = '0.00';

  /**
   * Should be float but API4 doesn't support that as param (yet?)
   * @var string
   */
  protected string $sales_unit_price = '0.00';

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

    $itemParameters = [
      'code' => $this->code,
      'name' => substr($this->name, 0, 50),
      'description' => substr($this->description, 0, 4000),
      'purchase_description' => substr($this->purchase_description, 0, 4000),
      'unit_price' => $this->purchase_unit_price,
      'sales_unit_price' => $this->sales_unit_price,
      'purchase_unit_price' => $this->purchase_unit_price,
      'is_sold' => $this->is_sold,
      'is_purchased' => $this->is_purchased,
    ];

    $trackingOption = $xero->createItem($itemParameters);
    $result->exchangeArray($trackingOption);
    return $result;
  }

}
