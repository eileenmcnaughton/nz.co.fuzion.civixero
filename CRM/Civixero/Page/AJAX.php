<?php

use CRM_Civixero_ExtensionUtil as E;

class CRM_Civixero_Page_AJAX extends CRM_Core_Page {

  /**
   * Function get contact sync errors by id
   *
   */
  public static function contactSyncErrors() {
    $syncerrors = [];
    if (CRM_Utils_Array::value('xeroerrorid', $_REQUEST)) {
      $xeroerrorid = CRM_Utils_Type::escape($_REQUEST['xeroerrorid'], 'Integer');
      $accountcontact = civicrm_api3("AccountContact", "get", [
        "id" => $xeroerrorid,
        "sequential" => TRUE,
      ]);
      if ($accountcontact["count"]) {
        $accountcontact = $accountcontact["values"][0];
        $syncerrors = $accountcontact["error_data"];
        $syncerrors = json_decode($syncerrors, TRUE);
      }
    }
    CRM_Utils_JSON::output($syncerrors);
  }

  /**
   * Function get invoice sync errors by id
   *
   */
  public static function invoiceSyncErrors() {
    $syncerrors = [];
    if (CRM_Utils_Array::value('xeroerrorid', $_REQUEST)) {
      $contactid = CRM_Utils_Type::escape($_REQUEST['xeroerrorid'], 'Integer');
      $contributions = getContactContributions($contactid);
      $invoices = getErroredInvoicesOfContributions($contributions);
      foreach ($invoices["values"] as $invoice) {
        $syncerrors = array_merge($syncerrors, json_decode($invoice["error_data"], TRUE));
      }
    }
    CRM_Utils_JSON::output($syncerrors);
  }

  /**
   * Function to retry the contact sync on fail by id
   *
   */
  public static function retryContactError() {
    $id = CRM_Utils_Request::retrieve('id', 'Integer');
    $accountcontact = civicrm_api3("AccountContact", "get", [
      "id" => $id,
      "sequential" => TRUE,
      "plugin" => "xero",
    ]);
    if ($accountcontact["count"]) {
      $accountcontact = $accountcontact["values"][0];
      $accountcontact["error_data"] = '';
      $accountcontact["accounts_needs_update"] = "1";
      civicrm_api3("AccountContact", "create", $accountcontact);
    }
    CRM_Utils_JSON::output([
      "status" => TRUE,
      "message" => "Record has been added into queue successfully.",
    ]);
  }

  /**
   * Function to retry the invoice sync on fail by id
   *
   */
  public static function retryInvoiceError() {
    $id = CRM_Utils_Request::retrieve('id', 'Integer');
    $accountinvoice = civicrm_api3("AccountInvoice", "get", [
      "id" => $id,
      "sequential" => TRUE,
      "plugin" => "xero",
    ]);
    if ($accountinvoice["count"]) {
      $accountinvoice = $accountinvoice["values"][0];
      $accountinvoice["error_data"] = '';
      $accountinvoice["accounts_needs_update"] = "1";
      civicrm_api3("AccountInvoice", "create", $accountinvoice);
    }
    CRM_Utils_JSON::output([
      "status" => TRUE,
      "message" => "Record has been added into queue successfully.",
    ]);
  }

  /**
   * Function get clear/dismiss the contact sync error
   *
   */
  public static function clearContactError() {
    $id = CRM_Utils_Request::retrieve('id', 'Integer');
    $accountcontact = civicrm_api3("AccountContact", "get", [
      "id" => $id,
      "sequential" => TRUE,
      "plugin" => "xero",
    ]);
    if ($accountcontact["count"]) {
      $accountcontact = $accountcontact["values"][0];
      $errordata = $accountcontact["error_data"];
      $errordata = json_decode($errordata, TRUE);
      $errordata["error_cleared"] = 1;
      $accountcontact["error_data"] = json_encode($errordata);

      civicrm_api3("AccountContact", "create", $accountcontact);
    }
    CRM_Utils_JSON::output([
      "status" => TRUE,
      "message" => "Sync error has been cleared successfully.",
    ]);
  }

  /**
   * Function get clear/dismiss the invoice sync error
   *
   */
  public static function clearInvoiceError() {
    $id = CRM_Utils_Request::retrieve('id', 'Integer');
    $accountinvoice = civicrm_api3("AccountInvoice", "get", [
      "id" => $id,
      "sequential" => TRUE,
      "plugin" => "xero",
    ]);
    if ($accountinvoice["count"]) {
      $accountinvoice = $accountinvoice["values"][0];
      $errordata = $accountinvoice["error_data"];
      $errordata = json_decode($errordata, TRUE);
      $errordata["error_cleared"] = 1;
      $accountinvoice["error_data"] = json_encode($errordata);
      $accountinvoice["accounts_needs_update"] = 0;
      civicrm_api3("AccountInvoice", "create", $accountinvoice);
    }
    CRM_Utils_JSON::output([
      "status" => TRUE,
      "message" => "Sync error has been cleared successfully.",
    ]);
  }
}
