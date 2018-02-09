<?php
use CRM_Civixero_ExtensionUtil as E;

class CRM_Civixero_Page_AJAX extends CRM_Core_Page {

  public static function contactSyncErrors() {
      $syncerrors = array();
      if(CRM_Utils_Array::value('xeroerrorid', $_REQUEST)) {
          $xeroerrorid = CRM_Utils_Type::escape($_REQUEST['xeroerrorid'], 'Integer');
          $accountcontact = civicrm_api3("AccountContact","get", array(
             "id"          => $xeroerrorid ,
              "sequential" => TRUE,
          ));
          if($accountcontact["count"]) {
              $accountcontact = $accountcontact["values"][0];
              $syncerrors = $accountcontact["error_data"];
              $syncerrors = json_decode($syncerrors, TRUE);
          }
      }
      CRM_Utils_JSON::output($syncerrors);
  }

    public static function invoiceSyncErrors() {
        $syncerrors = array();
        if(CRM_Utils_Array::value('xeroerrorid', $_REQUEST)) {
            $contactid = CRM_Utils_Type::escape($_REQUEST['xeroerrorid'], 'Integer');
            $contributions = getContactContributions($contactid);
            $invoices = getErroredInvoicesOfContributions($contributions);
            foreach($invoices["values"] as $invoice) {
                $syncerrors = array_merge($syncerrors, json_decode($invoice["error_data"], TRUE)) ;
            }
        }
        CRM_Utils_JSON::output($syncerrors);
    }
}
