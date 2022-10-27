<?php

use CRM_Civixero_ExtensionUtil as E;

class CRM_Civixero_Page_Inline_ContactSyncStatus extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addContactSyncStatusBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addContactSyncStatusBlock(CRM_Core_Page &$page, int $contactID) {
    $syncStatus = 0;

    try {
      $connectors = _civixero_get_connectors();
      $account_contact = civicrm_api3('AccountContact', 'getsingle', [
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin' => 'xero',
        'connector_id' => ['IN' => array_keys($connectors)],
      ]);

      if (!empty($account_contact['accounts_contact_id'])) {
        $syncStatus = 1;
      }
      elseif (!empty($account_contact['accounts_needs_update'])) {
        $syncStatus = 2;
      }

    }
    catch (Exception $e) {
      \Civi::log(E::SHORT_NAME)->error('Error getting sync status for contactID: ' . $contactID . ': ' . $e->getMessage());
      $syncStatus = 3;
    }

    $page->assign('syncStatus_xero', $syncStatus);
    $page->assign('contactID_xero', $contactID);
  }

}
