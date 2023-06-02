<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action creates a payment. It is based on API3 Payment.create and API3 MJWPayment.create
 *
 */
class TrackingCategoryDeleteOption extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * @var string The Xero Tracking Category ID
   *
   * @required
   */
  protected string $trackingCategoryID = '';

  /**
   * @var string The Tracking Category option ID
   *
   * @required
   */
  protected string $trackingOptionID = '';

  /**
   *
   * Note that the result class is that of the annotation below, not the h
   * in the method (which must match the parent class)
   *
   * @var \Civi\Api4\Generic\Result $result
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    if (empty($this->trackingCategoryID) || empty($this->trackingOptionID)) {
      throw new \CRM_Core_Exception('Both trackingCategoryID and trackingOptionID are required.');
    }
    $params = ['connector_id' => $this->connectorID];
    $xero = new \CRM_Civixero_TrackingCategory($params);
    $trackingOption = $xero->deleteOption($this->trackingCategoryID, $this->trackingOptionID);
    $result->exchangeArray($trackingOption);
    return $result;
  }

}
