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
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @param array $entities
 *   Existing entity array
 */
function civixero_civicrm_managed(&$entities) {
  if (civixero_is_extension_installed('nz.co.fuzion.connectors')) {
    $entities[] = array(
      'name' => 'CiviXero connector Type',
      'entity' => 'ConnectorType',
      'module' => 'nz.co.fuzion.civixero',
      'params' => array(
        'name' => 'CiviXero',
        'description' => 'CiviXero connector information',
        'module' => 'accountsync',
        'function' => 'credentials',
        'plugin' => 'xero',
        'field1_label' => 'Xero Key',
        'field2_label' => 'Xero Secret',
        'field3_label' => 'Xero Public Certificate Path',
        'field4_label' => 'Xero Private Key Path',
        'field5_label' => 'Settings',
        'version' => 3,
      ),
    );
  }
  return _civixero_civix_civicrm_managed($entities);
}

/**
 * Is a given extension installed.
 *
 * Currently adding very roughly just to support checking if connectors is installed.
 *
 * I like to snaffle hacks into their own function for easy later fixing :-)
 *
 * @todo - test using CRM_Extension_System::singleton()->getManager()->getStatus($key)
 *
 * @param string $extension
 *
 * @return bool
 */
function civixero_is_extension_installed($extension) {
  if ($extension == 'nz.co.fuzion.connectors') {
    if (function_exists('connectors_civicrm_entityTypes')) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @param array $caseTypes
 */
function civixero_civicrm_caseTypes(&$caseTypes) {
  _civixero_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_config().
 *
 * @param $metaDataFolders
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
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds entries to the navigation menu.
 *
 * @param array $menu
 */
function civixero_civicrm_navigationMenu(&$menu) {
  _Civixero_civix_insert_navigation_menu($menu, 'Administer', [
    'label' => 'Xero',
    'name' => 'Xero',
    'url' => NULL,
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => NULL,
  ]);
  _Civixero_civix_insert_navigation_menu($menu, 'Administer/Xero', [
    'label' => 'Xero Settings',
    'name' => 'Xero Settings',
    'url' => 'civicrm/xero/settings',
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => 0,
  ]);

  _Civixero_civix_insert_navigation_menu($menu, 'Administer/Xero', [
    'label' => 'Xero Error Logs',
    'name' => 'XeroErrorLogs',
    'url' => NULL,
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => 1,
  ]);

  _Civixero_civix_insert_navigation_menu($menu, 'Administer/Xero', [
    'label' => 'Synchronize contacts',
    'name' => 'Contact Sync',
    'url' => 'civicrm/a/#/accounts/contact/sync',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 1,
  ]);

  _Civixero_civix_insert_navigation_menu($menu, 'Administer/Xero/XeroErrorLogs', [
    'label' => 'Contact Errors',
    'name' => 'Contact Errors',
    'url' => 'civicrm/xero/errorlog',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);

  _Civixero_civix_insert_navigation_menu($menu, 'Administer/Xero/XeroErrorLogs', [
    'label' => 'Invoice Errors',
    'name' => 'Invoice Errors',
    'url' => 'civicrm/xero/errorlog?for=invoice',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);
}

/**
 * Gettings contributions of sinlge contact
 *
 * @param $contactid
 */
function getContactContributions($contactid) {
    $contributions = civicrm_api3("Contribution","get",array(
        "contact_id" => $contactid,
        "return"     => array("contribution_id"),
        "sequential" => TRUE
    ));
    $contributions = array_column($contributions["values"], "id");
    return $contributions;
}

/**
 * Gettings errored invoices of given contributions
 *
 * @param $contributions
 */
function getErroredInvoicesOfContributions($contributions) {
    $invoices = civicrm_api3("AccountInvoice","get",array(
        "plugin"          => "xero",
        "sequential"      => TRUE,
        "contribution_id" => array("IN" => $contributions),
        "error_data"      => array("<>" => "")
    ));
    return $invoices;
}

/**
 * Implementation of hook_civicrm_check.
 *
 * Add a check to the status page. Check if there are any account contact or invoice sync errors.
 *
 * @param $page
 */
function civixero_civicrm_check(&$messages) {

    $accountContactErrors = civicrm_api3("AccountContact","getcount",array(
        "error_data"  =>  array("NOT LIKE" => "%error_cleared%"),
        "plugin"      => "xero"
    ));
    $accountInvoiceErrors = civicrm_api3("AccountInvoice","getcount",array(
        "error_data"  =>  array("NOT LIKE" => "%error_cleared%"),
        "plugin"      => "xero"
    ));
    $errorMessage = "";
    $errorsPageUrl = CRM_Utils_System::url('civicrm/xero/errorlog');

    if($accountContactErrors > 0) {
        $errorMessage .= 'Found '.$accountContactErrors.' contact sync errors. <a href="'.$errorsPageUrl.'" target="_blank">Click here</a> to resolve them.';
        if($accountInvoiceErrors > 0) {
            $errorMessage .= "<br><br>";
        }
    }
    if($accountInvoiceErrors > 0) {
        $errorMessage .= 'Found '.$accountInvoiceErrors.' invoice sync errors. <a href="'.$errorsPageUrl.'?for=invoice" target="_blank">Click here</a> to resolve them.';
    }

    if($accountInvoiceErrors > 0 || $accountContactErrors >0) {
        $messages[] = new CRM_Utils_Check_Message(
            'civixero_sync_errors',
            $errorMessage,
            'Xero Sync Errors',
            \Psr\Log\LogLevel::ERROR,
            'fa-refresh'
        );
    }
  $xeroKeyPath = Civi::settings()->get('xero_public_certificate');
  if (!CRM_Utils_File::isIncludable($xeroKeyPath)) {
    $messages[] = new CRM_Utils_Check_Message(
      'civixero_keyinvalid',
      ts('Please configure a Xero api key - see https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/blob/master/README.md'),
      ts('No valid Xero api key'),
      \Psr\Log\LogLevel::WARNING,
      'fa-flag'
    );
  }
  elseif ($xeroKeyPath) {
    $certinfo = openssl_x509_parse(file_get_contents($xeroKeyPath));
    if (!empty($certinfo['validTo_time_t'])) {
      $validTo = new DateTime(date('Y-m-d H:i:s', $certinfo['validTo_time_t']));
      $current = new DateTime(date('Y-m-d H:i:s', time()));
      $interval = $validTo->diff($current);
      if ($interval->days < 30) {
        $messages[] = new CRM_Utils_Check_Message(
          'civixero_keyexpiry',
          ts('Your api key is expiring in %1 days. Please renew to let CiviCRM interact with Xero', array(1 => $interval->days)),
          ts('Xero Api Key Expiry'),
          \Psr\Log\LogLevel::WARNING,
          'fa-flag'
        );
      }
    }
  }
}

/**
 * Implements hook pageRun().
 *
 * Add Xero links to contact summary
 *
 * @param $page
 */
function civixero_civicrm_pageRun(&$page) {
  $pageName = get_class($page);
  if ($pageName != 'CRM_Contact_Page_View_Summary' || !CRM_Core_Permission::check('view all contacts')) {
    return;
  }

  if(($contactID = $page->getVar('_contactId')) != FALSE) {

    CRM_Civixero_Page_Inline_ContactSyncStatus::addContactSyncStatusBlock($page, $contactID);
    CRM_Civixero_Page_Inline_ContactSyncLink::addContactSyncLinkBlock($page, $contactID);
    CRM_Civixero_Page_Inline_InvoiceSyncLink::addInvoiceSyncLinkBlock($page, $contactID);
    CRM_Civixero_Page_Inline_ContactSyncErrors::addContactSyncErrorsBlock($page, $contactID);
    CRM_Civixero_Page_Inline_InvoiceSyncErrors::addInvoiceSyncErrorsBlock($page, $contactID);

    CRM_Core_Region::instance('contact-basic-info-left')->add(array(
      'template' => "CRM/Civixero/ContactSyncBlock.tpl",
    ));

  }

  CRM_Core_Resources::singleton()->addScriptFile('nz.co.fuzion.civixero','js/civixero_errors.js');
}

/**
 * Get available connectors.
 *
 * @return string
 */
function _civixero_get_connectors() {
  static $connectors = array();
  if (empty($connectors)) {
    try {
      $connectors = civicrm_api3('connector', 'get', array('connector_type_id' => 'CiviXero'));
      $connectors = $connectors['values'];
    }
    catch (CiviCRM_API3_Exception $e) {
      $connectors = array(0 => 0);
    }
  }
  return $connectors;
}

/**
 * @param $objectName
 * @param array $headers
 * @param $values
 * @param $selector
 */
function civixero_civicrm_searchColumns( $objectName, &$headers,  &$values, &$selector ) {
  if ($objectName == 'contribution') {
    foreach ($values as &$value) {
      try {
        $invoiceID = civicrm_api3('AccountInvoice', 'getvalue', array(
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

/**
 * Map xero accounts data to generic data.
 *
 * @param array $accountsData
 * @param string $entity
 * @param string $plugin
 */
function civixero_civicrm_mapAccountsData(&$accountsData, $entity, $plugin) {
  if ($plugin != 'xero' || $entity != 'contact') {
    return;
  }
  $accountsData['civicrm_formatted'] = array();
  $mappedFields = array(
    'Name' => 'display_name',
    'FirstName' => 'first_name',
    'LastName' => 'last_name',
    'EmailAddress' => 'email',
  );
  foreach ($mappedFields as $xeroField => $civicrmField) {
    if (!empty($accountsData[$xeroField])) {
      $accountsData['civicrm_formatted'][$civicrmField] = $accountsData[$xeroField];
    }
  }

  if (is_array($accountsData['Addresses']) && is_array($accountsData['Addresses']['Address'])) {
    foreach ($accountsData['Addresses']['Address'] as $address) {
      if (count($address) > 1) {
        $addressMappedFields = array(
          'AddressLine1' => 'street_address',
          'City' => 'city',
          'PostalCode' => 'postal_code',
        );
        foreach ($addressMappedFields as $xeroField => $civicrmField) {
          if (!empty($address[$xeroField])) {
            $accountsData['civicrm_formatted'][$civicrmField] = $address[$xeroField];
          }
        }
        break;
      }
    }
  }

  if (is_array($accountsData['Phones']) && is_array($accountsData['Phones']['Phone'])) {
    foreach ($accountsData['Phones']['Phone'] as $address) {
      if (count($address) > 1) {
        $addressMappedFields = array(
          'PhoneNumber' => 'phone',
        );
        foreach ($addressMappedFields as $xeroField => $civicrmField) {
          if (!empty($address[$xeroField])) {
            $accountsData['civicrm_formatted'][$civicrmField] = $address[$xeroField];
          }
        }
        break;
      }
    }
  }

}

/**
 * Implements hook_civicrm_accountsync_plugins().
 */
function civixero_civicrm_accountsync_plugins(&$plugins) {
  $plugins[] = 'xero';
}

/**
 * Implements hook_civicrm_contactSummaryBlocks().
 *
 * @link https://github.com/civicrm/org.civicrm.contactlayout
 */
function civixero_civicrm_contactSummaryBlocks(&$blocks) {
  $blocks += [
    'civixeroblock' => [
      'title' => ts('Civi Xero'),
      'blocks' => [],
    ]
  ];
  $blocks['civixeroblock']['blocks']['contactsyncstatus'] = [
    'title' => ts('Contact Sync Status'),
    'tpl_file' => 'CRM/Civixero/Page/Inline/ContactSyncStatus.tpl',
    'edit' => FALSE,
  ];
  $blocks['civixeroblock']['blocks']['contactsyncerrors'] = [
    'title' => ts('Contact Sync Errors'),
    'tpl_file' => 'CRM/Civixero/Page/Inline/ContactSyncErrors.tpl',
    'edit' => FALSE,
  ];
  $blocks['civixeroblock']['blocks']['invoicesyncerrors'] = [
    'title' => ts('Invoice Sync Errors'),
    'tpl_file' => 'CRM/Civixero/Page/Inline/InvoiceSyncErrors.tpl',
    'edit' => FALSE,
  ];
  $blocks['civixeroblock']['blocks']['invoicesynclink'] = [
    'title' => ts('Invoice Sync Link'),
    'tpl_file' => 'CRM/Civixero/Page/Inline/InvoiceSyncLink.tpl',
    'edit' => FALSE,
  ];
  $blocks['civixeroblock']['blocks']['contactsynclink'] = [
    'title' => ts('Contact Sync Link'),
    'tpl_file' => 'CRM/Civixero/Page/Inline/ContactSyncLink.tpl',
    'edit' => FALSE,
  ];

}
