<?php

class CRM_Civixero_Page_Inline_ContactSyncErrors extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addContactSyncErrorsBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addContactSyncErrorsBlock(&$page, $contactID) {
    $hasContactErrors = FALSE;

    try {
      $connectors = _civixero_get_connectors();
      $account_contact = civicrm_api3('account_contact', 'getsingle', [
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin' => 'xero',
        'connector_id' => ['IN' => array_keys($connectors)],
      ]);

      if (!empty($account_contact["error_data"])) {
        $hasContactErrors = TRUE;
      }

    }
    catch (Exception $e) {

    }

    $page->assign('hasContactErrors_xero', $hasContactErrors);
    $page->assign('contactID_xero', $contactID);

  }

}
