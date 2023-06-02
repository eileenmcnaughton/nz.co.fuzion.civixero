<?php

class CRM_Civixero_TrackingCategory extends CRM_Civixero_Base {

  /**
   * Pull TrackingCategories from Xero and temporarily stash them in a static variable.
   *
   * we don't want to keep stale ones in our DB - we'll check each time
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *  - I can't think of a reason why they would but it seems consistent
   *
   * @throws \Exception
   */
  public function pull() {
    CRM_Civixero_Base::isApiRateLimitExceeded(TRUE);

    static $trackingCategories = [];
    if (empty($trackingCategories)) {
      $where = 'Status=="' . \XeroAPI\XeroPHP\Models\Accounting\TrackingCategory::STATUS_ACTIVE . '"';
      $order = "Name ASC";

      try {
        $categories = $this->getAccountingApiInstance()->getTrackingCategories($this->getTenantID(), $where, $order, TRUE);
        $trackingCategories['count'] = count($categories);
        foreach ($categories as $category) {
          $trackingCategories[$category->getTrackingCategoryId()] = [
            'name' => $category->getName(),
            'status' => $category->getStatus(),
            'id' => $category->getTrackingCategoryId(),
            'optionsCount' => count($category->getOptions()),
          ];
          foreach ($category->getOptions() as $option) {
            $trackingCategories[$category->getTrackingCategoryId()]['options'][] = [
              'name' => $option->getName(),
              'status' => $option->getStatus(),
              'id' => $option->getTrackingOptionId(),
            ];
          }
        }
      } catch (\Exception $e) {
        \Civi::log('civixero')->error('Exception when calling AccountingApi->getTrackingCategories: ' . $e->getMessage());
        throw $e;
      }
    }
    return $trackingCategories;
  }

  /**
   * @throws \Exception
   */
  public function addOption(string $trackingCategoryID, string $trackingOptionName): array {
    CRM_Civixero_Base::isApiRateLimitExceeded(TRUE);

    $apiInstance = $this->getAccountingApiInstance();

    $trackingOption = new XeroAPI\XeroPHP\Models\Accounting\TrackingOption;
    $trackingOption->setName($trackingOptionName);
    $idempotencyKey = md5(rand() . microtime());

    try {
      $trackingCategoryOption = $this->checkIfCategoryHasOption($trackingCategoryID, $trackingOptionName);
      if (!empty($trackingCategoryOption)) {
        return $trackingCategoryOption;
      }
      $result = $apiInstance->createTrackingOptions($this->getTenantID(), $trackingCategoryID, $trackingOption, $idempotencyKey);
      return [
        'name' => $result->getOptions()[0]->getName(),
        'status' => $result->getOptions()[0]->getStatus(),
        'id' => $result->getOptions()[0]->getTrackingOptionId(),
        'added' => TRUE,
      ];
    } catch (\Exception $e) {
      \Civi::log('civixero')->error('Exception when calling AccountingApi->createTrackingOptions: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * @throws \Exception
   */
  public function deleteOption(string $trackingCategoryID, string $trackingOptionID): array {
    CRM_Civixero_Base::isApiRateLimitExceeded(TRUE);

    $apiInstance = $this->getAccountingApiInstance();

    try {
      $result = $apiInstance->deleteTrackingOptions($this->getTenantID(), $trackingCategoryID, $trackingOptionID);
      return [
        'name' => $result->getOptions()[0]->getName(),
        'status' => $result->getOptions()[0]->getStatus(),
        'id' => $result->getOptions()[0]->getTrackingOptionId(),
        'deleted' => TRUE,
      ];
    } catch (\Exception $e) {
      \Civi::log('civixero')->error('Exception when calling AccountingApi->deleteTrackingOptions: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * @throws \Exception
   */
  public function checkIfCategoryHasOption(string $trackingCategoryID, string $trackingOptionName): array {
    $trackingCategories = $this->pull();
    if (!isset($trackingCategories[$trackingCategoryID])) {
      throw new \Exception('Tracking Category with ID ' . $trackingCategoryID . ' does not exist');
    }

    foreach ($trackingCategories[$trackingCategoryID]['options'] as $trackingCategoryOption) {
      if ($trackingCategoryOption['name'] === $trackingOptionName) {
        $trackingCategoryOption['added'] = FALSE;
        return $trackingCategoryOption;
      }
    }
    return [];
  }

}
