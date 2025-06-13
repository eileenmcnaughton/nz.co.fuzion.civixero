<?php

namespace Civi\Api4\Action\Xero;

use CRM_Civixero_ExtensionUtil as E;

/**
 * This API Action retrieves Items from Xero
 *
 */
class InvoicePull extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Connector ID (default 0)
   *
   * @var int
   */
  protected int $connectorID = 0;

  /**
   * Searches across the fields InvoiceNumber/Reference
   * @var string
   */
  protected string $searchTerm = '';

  /**
   * Comma-separated list of Xero Invoice IDs to return (eg. 297c2dc5-cc47-4afd-8ec8-74990b8761e9)
   *
   * @var string
   */
  protected string $xeroInvoiceIDs = '';

  /**
   * Comma-separated list of Xero InvoiceNumbers to return (eg. INV-01544)
   * @var string
   */
  protected string $xeroInvoiceNumbers = '';

  /**
   * Comma-separated list of Xero ContactIDs to return (eg. 025867f1-d741-4d6b-b1af-9ac774b59ba7)
   * @var string
   */
  protected string $xeroContactIDs = '';

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
    $xero = new \CRM_Civixero_Invoice($params);
    $items = $xero->pullFromXero($this->includeArchived, $this->summaryOnly, $this->searchTerm, $this->page, $this->pageSize, $this->ifModifiedSinceDateTime, $this->xeroInvoiceIDs, $this->xeroInvoiceNumbers, $this->xeroContactIDs);
    $result->exchangeArray($items ?? []);
    return $result;
  }

}
