<?php

require_once 'Civixero.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function civixero_civicrm_config(&$config) {
  _civixero_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function civixero_civicrm_xmlMenu(&$files) {
  _civixero_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function civixero_civicrm_install() {
  return _civixero_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function civixero_civicrm_uninstall() {
  return _civixero_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function civixero_civicrm_enable() {
  return _civixero_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function civixero_civicrm_disable() {
  return _civixero_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function civixero_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _civixero_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function civixero_civicrm_managed(&$entities) {
  return _civixero_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 */
function civixero_civicrm_caseTypes(&$caseTypes) {
  _civixero_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_config
 */
function civixero_civicrm_alterSettingsFolders(&$metaDataFolders){
  static $configured = FALSE;
  if ($configured) return;
  $configured = TRUE;

  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'settings';
  if(!in_array($extDir, $metaDataFolders)){
    $metaDataFolders[] = $extDir;
  }
}

/**
 * Implementation of hook_civicrm_navigationMenu
 *
 * Adds entries to the navigation menu
 * @param unknown $menu
 */
function civixero_civicrm_navigationMenu(&$menu) {
  $maxID = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  $navId = $maxID + 1;
  $menu[$navId] = array (
    'attributes' => array (
      'label' => 'Xero',
      'name' => 'Xero',
      'url' => null,
      'permission' => 'administer CiviCRM',
      'operator' => null,
      'separator' => null,
      'parentID' => null,
      'active' => 1,
    ),
    'child' => array (
      $navId+1 => array(
        'attributes' => array(
          'label' => 'Xero Settings',
          'name' => 'Xero Settings',
          'url' => 'civicrm/xero/settings',
          'permission' => 'administer CiviCRM',
          'operator' => null,
          'separator' => 1,
          'active' => 1,
          'parentID'   => $navId,
        ),),
      $navId+2 => array (
        'attributes' => array(
          'label' => 'Xero Dashboard',
          'name' => 'Xero Dashboard',
          'url' => 'civicrm/xero/dashboard',
          'permission' => 'administer CiviCRM',
          'operator' => null,
          'separator' => 1,
          'active' => 1,
          'parentID'   => $navId,
        )))
  );
}

/**
 * Implementation of hook pageRun
 * Add Xero links to contact summary
 * @param $page
 */
function civixero_civicrm_pageRun(&$page) {
  $pageName = get_class($page);
   if($pageName != 'CRM_Contact_Page_View_Summary' || !CRM_Core_Permission::check('view all contacts')) {
    return;
  }
  if(($contactID = $page->getVar('_contactId')) != FALSE) {
    try{
      $xeroID = civicrm_api3('account_contact', 'getvalue', array(
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id',
        'plugin' => 'xero',
      ));
    }
    catch(Exception $e) {
      //no record
      return;
    }
  }
  else {
    return;
  }
  // link =
  $xeroLinks = array(
    'view_transactions' => array(
      'link' => 'https://go.xero.com/Reports/report.aspx?reportId=be392447-762b-444d-9cde-87c6bd185d00&report=TransactionsByContact&invoiceType=INVOICETYPE%2fACCREC&addToReportId=cf6fedeb-2188-493c-96e2-b862198f9b46&addToReportTitle=Income+by+Contact&reportClass=TransactionsByContact&contact=',
      'link_label' => 'Xero Transactions',
    ),
    'view_contact' => array(
      'link' => 'https://go.xero.com/Contacts/View.aspx?contactID=',
      'link_label' => 'Xero Contact',
    )
  );

  $xeroBlock = "<div class='crm-summary-row'>";
  foreach ($xeroLinks as $link) {
    $xeroBlock .= "<div class='crm-content'><a href='{$link['link']}{$xeroID}'>{$link['link_label']}</a></div>";
  }

  $xeroBlock .= "</div>";


  CRM_Core_Region::instance('contact-basic-info-left')->add(array(
  'markup' => $xeroBlock
  ));

  //https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=
}

/**
 * @param $objectName
 * @param $headers
 * @param $values
 * @param $selector
 */
function civixero_civicrm_searchColumns( $objectName, &$headers,  &$values, &$selector ) {
  if ($objectName == 'contribution') {
    foreach ($values as &$value) {
      try {
        $invoiceID = civicrm_api3('account_invoice', 'getvalue', array(
          'plugin' => 'xero',
          'contribution_id' => $value['contribution_id'],
          'return' => 'accounts_invoice_id',
        ));
        $value['contribution_status'] .= "<a href='https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=" . $invoiceID . "'> <p>Xero</p></a>";
      }
      catch (Exception $e) {
        continue;
      }
    }
  }
}
