<?php

class CRM_Civixero_Item extends CRM_Civixero_Base {

  /**
   * pull Item Codes from Xero and temporarily stash them on static
   * we don't want to keep stale ones in our DB - we'll check each time
   * We call the civicrm_accountPullPreSave hook so other modules can alter if required
   *  - I can't think of a reason why they would but it seems consistent
   *
   * @param array $params
   * @throws API_Exception
   */
  function pull($params) {
    static $items = array();
    if(empty($items)){
      $items = $this->getSingleton()->Items();
      $items = $items['Items']['Item'];
    }
    return $items;
  }
}
