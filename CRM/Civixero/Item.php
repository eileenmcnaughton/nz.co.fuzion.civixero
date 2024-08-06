<?php

class CRM_Civixero_Item extends CRM_Civixero_Base {

  /**
   * Pull Items from Xero
   */
  public function pull(array $filters) {
    static $items = [];
    if (empty($items)) {
      $order = "Name ASC";
      $where = $filters['where'] ?? NULL;
      $modifiedSince = NULL;
      // $modifiedSince = date('Y-m-dTH:i:s', strtotime('20240101000000'));

      try {
        $xeroItems = $this->getAccountingApiInstance()->getItems($this->getTenantID(), $modifiedSince, $where, $order);
        foreach ($xeroItems as $xeroItem) {
          /**
           * @var \XeroAPI\XeroPHP\Models\Accounting\Item $xeroItem
           */
          foreach ($xeroItem::attributeMap() as $localName => $originalName) {
            $getter = 'get' . $originalName;
            switch ($localName) {
              case 'purchase_details':
              case 'sales_details':
                foreach ($xeroItem->$getter()::attributeMap() as $localSubName => $originalSubName) {
                  $subGetter = 'get' . $originalSubName;
                  $item[$localName][$localSubName] = $xeroItem->$getter()->$subGetter();
                }
                break;

              default:
                $item[$localName] = $xeroItem->$getter();
            }
          }
          $items[$item['item_id']] = $item;
        }
      } catch (\Exception $e) {
        \Civi::log('civixero')->error('Exception when calling AccountingApi->getItems: ' . $e->getMessage());
        throw $e;
      }
    }
    return $items;
  }

  /**
   * @throws \Exception
   */
  public function createItem(array $itemParameters): array {
    $apiInstance = $this->getAccountingApiInstance();

    $item = new XeroAPI\XeroPHP\Models\Accounting\Item;
    foreach ($item::attributeMap() as $localName => $originalName) {
      $setter = 'set' . $originalName;
      if (isset($itemParameters[$localName])) {
        $item->$setter($itemParameters[$localName]);
      }
    }
    $idempotencyKey = md5(rand() . microtime());
    $items = new XeroAPI\XeroPHP\Models\Accounting\Items();
    $items->setItems([$item]);

    try {
      $result = $apiInstance->updateOrCreateItems($this->getTenantID(), $items, FALSE, NULL, $idempotencyKey);
      foreach ($result->getItems() as $item) {
        return [
          'itemID' => $item->getItemId(),
          'itemCode' => $item->getItemCode(),
        ];
      }
    } catch (\Exception $e) {
      \Civi::log('civixero')->error('Exception when calling AccountingApi->updateOrCreateItems: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * @throws \Exception
   */
  public function deleteItem(string $itemID): array {
    $apiInstance = $this->getAccountingApiInstance();

    try {
      $result = $apiInstance->deleteItem($this->getTenantID(), $itemID);
      return [
        'itemID' => $itemID,
        'deleted' => TRUE,
      ];
    } catch (\Exception $e) {
      if ($e instanceof XeroAPI\XeroPHP\ApiException && $e->getCode() == 404) {
        // Already deleted.
        return [
          'itemID' => $itemID,
          'notFound' => TRUE,
        ];
      }
      \Civi::log('civixero')->error('Exception when calling AccountingApi->deleteItem: ' . $e->getMessage());
      throw $e;
    }
  }

}
