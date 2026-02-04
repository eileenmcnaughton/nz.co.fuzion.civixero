<?php

use Civi\Api4\AccountContact;

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
  public static function addContactSyncErrorsBlock($page, $contactID) {
    try {
      $connectors = _civixero_get_connectors();
      $accountContact = AccountContact::get(FALSE)
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('plugin', '=', 'xero')
        ->addWhere('connector_id', 'IN', array_keys($connectors))
        ->addWhere('error_data', 'IS NOT EMPTY')
        ->addWhere('is_error_resolved', '=', FALSE)
        ->execute()
        ->first();

      if (!empty($accountContact)) {
        $hasContactErrors = TRUE;
      }
    }
    catch (Exception $e) {

    }

    $page->assign('hasContactErrors_xero', $hasContactErrors ?? FALSE);
    $page->assign('contactID_xero', $contactID);
  }

}
