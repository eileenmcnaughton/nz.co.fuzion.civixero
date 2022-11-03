<?php

class CRM_Civixero_Page_Inline_ContactSyncLink extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addContactSyncLinkBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addContactSyncLinkBlock(&$page, $contactID) {

    $isContactSynced = 0;

    try {
      $connectors = _civixero_get_connectors();
      $account_contact = civicrm_api3('AccountContact', 'getsingle', [
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin' => 'xero',
        'connector_id' => ['IN' => array_keys($connectors)],
      ]);

      if (!empty($account_contact['accounts_contact_id'])) {
        $isContactSynced = TRUE;
        $page->assign('accountContactId_xero', $account_contact['accounts_contact_id']);
      }

    }
    catch (Exception $e) {

    }

    $page->assign('isContactSynced_xero', $isContactSynced);
    $page->assign('contactID_xero', $contactID);

  }

}
