<?php

use Civi\Api4\AccountContact;
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
  public static function addContactSyncStatusBlock(CRM_Core_Page $page, int $contactID): void {
    $syncStatus = 0;

    $connectors = _civixero_get_connectors();
    $account_contact = AccountContact::get(FALSE)
      ->addSelect('accounts_contact_id', 'accounts_needs_update', 'connector_id', 'error_data', 'id', 'contact_id')
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('plugin', '=', 'xero')
      ->addWhere('connector_id', 'IN', array_keys($connectors))
      ->execute()
      ->first();

    if (!empty($account_contact['accounts_contact_id'])) {
      $syncStatus = 1;
    }
    elseif (!empty($account_contact['accounts_needs_update'])) {
      $syncStatus = 2;
    }

    $page->assign('syncStatus_xero', $syncStatus);
    $page->assign('contactID_xero', $contactID);
  }

}
