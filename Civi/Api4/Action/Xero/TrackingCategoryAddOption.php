<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action creates a payment. It is based on API3 Payment.create and API3 MJWPayment.create
 *
 */
class TrackingCategoryAddOption extends \Civi\Api4\Generic\AbstractCreateAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * @var string The Xero Tracking Category ID
   */
  protected string $trackingCategoryID;

  /**
   * @var string The Tracking Category option name
   */
  protected string $optionName;

  /**
   *
   * Note that the result class is that of the annotation below, not the h
   * in the method (which must match the parent class)
   *
   * @var \Civi\Api4\Generic\Result $result
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $params = ['connector_id' => $this->connectorID];
    $xero = new \CRM_Civixero_TrackingCategory($params);
    $trackingOption = $xero->addOption($this->trackingCategoryID, $this->optionName);
    $result->exchangeArray($trackingOption);
    return $result;
  }

}
