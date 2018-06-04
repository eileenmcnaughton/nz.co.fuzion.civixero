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
  $maxID = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  $navId = $maxID + 287;

  // Get the id of System Settings Menu
  $administerMenuId = CRM_Core_DAO::getFieldValue('CRM_Core_BAO_Navigation', 'Administer', 'id', 'name');
  $parentID = !empty($administerMenuId) ? $administerMenuId : NULL;

  $navigationMenu = array(
    'attributes' => array(
      'label' => 'Xero',
      'name' => 'Xero',
      'url' => NULL,
      'permission' => 'administer CiviCRM',
      'operator' => NULL,
      'separator' => NULL,
      'parentID' => $parentID,
      'active' => 1,
      'navID' => $navId,
    ),
    'child' => array(
      $navId + 1 => array(
        'attributes' => array(
          'label' => 'Xero Settings',
          'name' => 'Xero Settings',
          'url' => 'civicrm/xero/settings',
          'permission' => 'administer CiviCRM',
          'operator' => NULL,
          'separator' => 0,
          'active' => 1,
          'parentID' => $navId,
          'navID' => $navId + 1,
        ),
      ),

        $navId + 2 => array(
            'attributes' => array(
                'label' => 'Xero Error Logs',
                'name' => 'Xero Error Logs',
                'url' => NULL,
                'permission' => 'administer CiviCRM',
                'operator' => NULL,
                'separator' => 1,
                'active' => 1,
                'parentID' => $navId,
                'navID' => $navId + 2,
            ),
            'child' => array(
                $navId+4 => array (
                  'attributes' => array(
                    'label' => 'Contact Errors',
                    'name' => 'Contact Errors',
                    'url' => 'civicrm/xero/errorlog',
                    'permission' => 'administer CiviCRM',
                    'operator' => null,
                    'separator' => 0,
                    'active' => 1,
                    'parentID'   => $navId + 2,
                  )
                ),
                $navId+5 => array (
                  'attributes' => array(
                    'label' => 'Invoice Errors',
                    'name' => 'Invoice Errors',
                    'url' => 'civicrm/xero/errorlog?for=invoice',
                    'permission' => 'administer CiviCRM',
                    'operator' => null,
                    'separator' => 0,
                    'active' => 1,
                    'parentID'   => $navId + 5,
                  )
                ),
            ),
        ),

      $navId+3 => array (
        'attributes' => array(
          'label' => 'Synchronize contacts',
          'name' => 'Contact Sync',
          'url' => 'civicrm/a/#/accounts/contact/sync',
          'permission' => 'administer CiviCRM',
          'operator' => null,
          'separator' => 1,
          'active' => 1,
          'parentID'   => $navId + 3,
        ))

    ),
  );
  if ($parentID) {
    $menu[$parentID]['child'][$navId] = $navigationMenu;
  }
  else {
    $menu[$navId] = $navigationMenu;
  }
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

function _civixero_append_sync_errors(&$xeroBlock, $account_contact) {
    if(empty($account_contact['accounts_needs_update']) && !empty($account_contact["error_data"])) {
        $xeroBlock .= "<p class='xeroerror'> Contact <span class='error'>sync error</span> with Xero <a href='#' class='helpicon error xeroerror-info' data-xeroerrorid='".$account_contact["id"]."'></a></p>";
    }

    if (!empty($account_contact['accounts_contact_id'])) {
        $contributions = getContactContributions($account_contact["contact_id"]);
        if(count($contributions)) {
            $invoices = getErroredInvoicesOfContributions($contributions);
            if($invoices["count"]) {
                $xeroBlock .= "<p class='xeroerror second'> ".$invoices["count"]." Contribution".(($invoices["count"] > 1)? 's' : '')." <span class='error'>not synced</span> with Xero <a href='#' class='helpicon error xeroerror-invoice-info' data-xeroerrorid='".$account_contact["contact_id"]."'></a></p>";
            }
        }
    }
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
    $connectors = _civixero_get_connectors();
    $xeroBlock = '';
    try{
      $account_contacts = civicrm_api3('account_contact', 'get', array(
        'contact_id' => $contactID,
        'return' => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin' => 'xero',
        'connector_id' => array('IN' => array_keys($connectors)),
      ));

      if (!$account_contacts['count']) {
        throw new Exception('Contact needs syncing');
      }
      foreach ($account_contacts['values'] as $account_contact) {
        $prefix = _civixero_get_connector_prefix($account_contact['connector_id']);
        if (!empty($account_contact['accounts_contact_id'])) {
          $xeroBlock .= _civixero_get_xero_links_block($account_contact, $prefix);
        }
        elseif (!empty($account_contact['accounts_needs_update'])) {
          $xeroBlock .= _civicrm_get_xero_block_header();
          $xeroBlock .= "<p> Contact is queued for sync with Xero</p></div>";
        } elseif(!empty($account_contact["error_data"])) {
            $xeroBlock .= _civicrm_get_xero_block_header();
            _civixero_append_sync_errors($xeroBlock, $account_contact);
            $xeroBlock .= "</div>";
        }
      }
    }
    catch(Exception $e) {
      $xeroBlock = "<div class='crm-summary-row'>" .
        "<a href='#' id='xero-sync' data-contact-id=$contactID>
        Queue Sync to Xero</a></div>";

      CRM_Core_Region::instance('contact-basic-info-left')->add(array(
        'markup' => $xeroBlock,
        'type' => 'markup',
      ));
      $createString = '';
      if (!empty($account_contact) && !empty($account_contact['id'])) {
        $createString = "'id' : '" . $account_contact['id'] . "',";
      }
      $script = "cj('#xero-sync').click(function( event) {
        event.preventDefault();
        CRM.api('account_contact', 'create',
         {'contact_id' : cj(this).data('contact-id'),
           'plugin' : 'xero',
           $createString
           'accounts_needs_update' : 1
         });
        cj(this).replaceWith('Xero sync is queued');
      });";
      CRM_Core_Region::instance('contact-basic-info-left')->add(array(
        'script' => $script
      ));
      return;
    }
  }
  else {
    return;
  }

  CRM_Core_Region::instance('contact-basic-info-left')->add(array(
    'markup' => $xeroBlock,
    'type' => 'markup',
  ));

  CRM_Core_Resources::singleton()->addStyleFile('nz.co.fuzion.civixero','css/civixero_styles.css');
  CRM_Core_Resources::singleton()->addScriptFile('nz.co.fuzion.civixero','js/civixero_errors.js');

  //https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=
}

/**
 * Get prefix for the connector.
 *
 * @param int|null $connector_id
 *
 * @return string
 */
function _civixero_get_connector_prefix($connector_id) {
  if (!$connector_id) {
    return '';
  }
  $connectors = _civixero_get_connectors();
  return $connectors[$connector_id]['name'];
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
 * @param $xeroID
 *
 * @return string
 */
function _civixero_get_xero_links_block($account_contact, $connector_name) {
  $xeroID = $account_contact['accounts_contact_id'];
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

  $xeroBlock = _civicrm_get_xero_block_header() . $connector_name;
  foreach ($xeroLinks as $link) {
    $xeroBlock .= "<div class='crm-content'><a href='{$link['link']}{$xeroID}'>{$link['link_label']}</a></div>";
  }
  _civixero_append_sync_errors($xeroBlock, $account_contact);
  $xeroBlock .= "</div>";
  return $xeroBlock;
}

/**
 * Get header for Xero block added to summary screen.
 *
 * @return string
 */
function _civicrm_get_xero_block_header() {
  return "<div class='crm-summary-row'>";
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
