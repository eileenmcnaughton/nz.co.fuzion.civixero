<?php

use Civi\Api4\AccountInvoice;

class CRM_Civixero_Page_Inline_InvoiceSyncErrors extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addInvoiceSyncErrorsBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addInvoiceSyncErrorsBlock($page, int $contactID): void {
    try {
      $erroredInvoiceCount = AccountInvoice::get(FALSE)
        ->selectRowCount()
        ->addWhere('contribution_id.contact_id', '=', $contactID)
        ->addWhere('plugin', '=', 'xero')
        ->addWhere('error_data', 'IS NOT EMPTY')
        ->addWhere('is_error_resolved', '=', FALSE)
        ->execute()
        ->countMatched();
      if ($erroredInvoiceCount > 0) {
        $hasInvoiceErrors = TRUE;
        $page->assign('erroredInvoices_xero', $erroredInvoiceCount);
      }
    }
    catch (Exception $e) {

    }
    $page->assign('hasInvoiceErrors_xero', $hasInvoiceErrors ?? FALSE);
    $page->assign('contactID_xero', $contactID);
  }

}
