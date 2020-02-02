<?php

use CRM_Civixero_ExtensionUtil as E;

class CRM_Civixero_Page_XeroErrorLogs extends CRM_Core_Page {

  private $errorsFor = "contact";

  private $pageTitle = "";

  private $retryUrl = "";

  private $clearUrl = "";

  public function run() {
    $this->setupPage();
    $this->initializePager();
    $this->assign('syncErrors', $this->getSyncErrors());
    parent::run();
  }

  /**
   * Function to setup page values
   *
   * @access protected
   */
  protected function setupPage() {
    $for = CRM_Utils_Request::retrieve('for', 'String');
    $this->retryUrl = 'civicrm/ajax/civixero/sync/contact/errors/retry';
    $this->clearUrl = 'civicrm/ajax/civixero/sync/contact/errors/clear';

    if ($for == 'invoice') {
      $this->errorsFor = $for;
      $this->retryUrl = 'civicrm/ajax/civixero/sync/invoice/errors/retry';
      $this->clearUrl = 'civicrm/ajax/civixero/sync/invoice/errors/clear';
    }
    $this->assign("errorsfor", $this->errorsFor);

    CRM_Utils_System::setTitle(E::ts('Xero %1 error logs', [1 => $this->errorsFor]));
    CRM_Core_Resources::singleton()->addStyleFile('nz.co.fuzion.civixero', 'css/civixero_styles.css');
  }

  /**
   * Function to get the sync errors
   *
   * @return array $syncerrors
   * @access protected
   */
  protected function getSyncErrors() {
    $syncerrors = [];
    list($offset, $limit) = $this->_pager->getOffsetAndRowCount();
    if ($this->errorsFor == "contact") {
      $accountcontacts = civicrm_api3("AccountContact", "get", [
        "error_data" => ["NOT LIKE" => "%error_cleared%"],
        "plugin" => "xero",
        "return" => ["contact_id.display_name", "accounts_contact_id", "error_data", "last_sync_date", "contact_id"],
        "options" => ['limit' => $limit, 'offset' => $offset, 'sort' => 'id DESC'],
      ]);

      $accountcontacts = $accountcontacts["values"];
      $this->formatErrors($accountcontacts);
      $this->formatContactsInfo($accountcontacts);
      $this->formatUrl($accountcontacts);
      return $accountcontacts;
    }

    $accountinvoices = civicrm_api3("AccountInvoice", "get", [
      "error_data" => ["NOT LIKE" => "%error_cleared%"],
      "plugin" => "xero",
      "return" => ["contribution_id", "contribution_id.contact_id", "contribution_id.contact_id.display_name", "accounts_invoice_id", "error_data", "last_sync_date"],
      "options" => ['limit' => $limit, 'offset' => $offset],
    ]);
    $accountinvoices = $accountinvoices["values"];
    $this->formatErrors($accountinvoices);
    $this->formatContactsInfo($accountinvoices);
    $this->formatUrl($accountinvoices);
    return $accountinvoices;
  }

  /**
   * Method to format JSON errors
   *
   * @access protected
   */
  protected function formatErrors(&$syncerrors) {
    foreach ($syncerrors as $index => $syncerror) {
      $syncerrors[$index]["error_data"] = json_decode($syncerror["error_data"], TRUE);
    }
  }

  /**
   * Method to format Contacts Name
   *
   * @access protected
   */
  protected function formatContactsInfo(&$syncerrors) {
    foreach ($syncerrors as $index => $syncerror) {
      if (array_key_exists("contact_id.display_name", $syncerror)) {
        $syncerrors[$index]["contactname"] = $syncerror["contact_id.display_name"];
      }
      else {
        $syncerrors[$index]["contactname"] = $syncerror["contribution_id.contact_id.display_name"];
      }

      if (array_key_exists("contribution_id.contact_id", $syncerror)) {
        $syncerrors[$index]["contact_id"] = $syncerror["contribution_id.contact_id"];
      }
    }
  }

  /**
   * Method to format Url
   *
   * @access protected
   */
  protected function formatUrl(&$syncerrors) {
    foreach ($syncerrors as $index => $syncerror) {
      $syncerrors[$index]['retryUrl'] = CRM_Utils_System::url($this->retryUrl, ['id' => $syncerror['id']]);
      $syncerrors[$index]['clearUrl'] = CRM_Utils_System::url($this->clearUrl, ['id' => $syncerror['id']]);
    }
  }

  /**
   * Method to initialize pager
   *
   * @access protected
   */
  protected function initializePager() {
    $entity = ($this->errorsFor == 'contact' ? 'AccountContact' : 'AccountInvoice');
    $totalitems = civicrm_api3($entity, "getcount", [
      "error_data" => ["NOT LIKE" => "%error_cleared%"],
      "plugin" => "xero",
    ]);
    $params = [
      'total' => $totalitems,
      'rowCount' => CRM_Utils_Pager::ROWCOUNT,
      'status' => ts('Synchronization Errors %%StatusMessage%%'),
      'buttonBottom' => 'PagerBottomButton',
      'buttonTop' => 'PagerTopButton',
      'pageID' => $this->get(CRM_Utils_Pager::PAGE_ID),
    ];
    $this->_pager = new CRM_Utils_Pager($params);
    $this->assign_by_ref('pager', $this->_pager);
  }

}
