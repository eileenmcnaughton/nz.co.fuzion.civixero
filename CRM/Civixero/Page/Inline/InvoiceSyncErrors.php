<?php

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
  public static function addInvoiceSyncErrorsBlock(&$page, $contactID) {

    $hasInvoiceErrors = FALSE;

    try {
      $connectors = _civixero_get_connectors();
      $account_contact = civicrm_api3('account_contact', 'getsingle', [
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin' => 'xero',
        'connector_id' => ['IN' => array_keys($connectors)],
      ]);

      $contributions = getContactContributions($account_contact["contact_id"]);
      if (count($contributions)) {
        $invoices = getErroredInvoicesOfContributions($contributions);
        if ($invoices["count"]) {
          $hasInvoiceErrors = TRUE;
          $page->assign('erroredInvoices_xero', $invoices["count"]);
        }
      }

    }
    catch (Exception $e) {

    }

    $page->assign('hasInvoiceErrors_xero', $hasInvoiceErrors);
    $page->assign('contactID_xero', $contactID);

  }

}
