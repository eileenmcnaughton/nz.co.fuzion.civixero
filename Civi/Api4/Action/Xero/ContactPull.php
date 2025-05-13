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
  protected string $searchTerm = '';

  /**
   * @var string
   */
  protected string $ifModifiedSinceDateTime = '-1 week';

  /**
   * @var bool
   */
  protected bool $includeArchived = FALSE;

  /**
   * @var bool
   */
  protected bool $summaryOnly = FALSE;

  /**
   * @var int
   */
  protected int $page = 1;

  /**
   * @var int
   */
  protected int $pageSize = 100;

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
    $items = $xero->pullFromXero($filters ?? [], $this->includeArchived, $this->summaryOnly, $this->searchTerm, $this->page, $this->pageSize, $this->ifModifiedSinceDateTime);
    $result->exchangeArray($items ?? []);
    return $result;
  }

}
