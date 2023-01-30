<?php

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\AccountContact;
use Civi\Api4\AccountInvoice;
use Psr\Log\LogLevel;

require_once 'Civixero.civix.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @param \CRM_Core_Config $config
 */
function civixero_civicrm_config(CRM_Core_Config $config) {
  _civixero_civix_civicrm_config($config);
  require_once __DIR__ . '/vendor/autoload.php';
  if (!function_exists('random_bytes')) {
    require_once(__DIR__ . '/vendor/paragonie/random_compat/lib/random.php');
  }
}

/**
 * Implementation of hook_civicrm_install
 */
function civixero_civicrm_install() {
  _civixero_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 */
function civixero_civicrm_enable() {
  _civixero_civix_civicrm_enable();
}

/**
 * Is a given extension installed.
 *
 * Currently adding very roughly just to support checking if connectors is installed.
 *
 * I like to snaffle hacks into their own function for easy later fixing :-)
 *
 * @param string $extension
 *
 * @return bool
 * @todo - test using CRM_Extension_System::singleton()->getManager()->getStatus($key)
 *
 */
function civixero_is_extension_installed(string $extension): bool {
  return ($extension === 'nz.co.fuzion.connectors') && function_exists('connectors_civicrm_entityTypes');
}

/**
 * Implements hook_civicrm_alterSettingsMetaData(().
 *
 * This hook sets the default for each setting to our preferred value.
 * It can still be overridden by specifically setting the setting.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
 */
function civixero_civicrm_alterSettingsMetaData(array &$settingsMetaData): void {
  $weight = 100;
  foreach ($settingsMetaData as $index => $setting) {
    if (($setting['group'] ?? '') === 'accountsync') {
      $settingsMetaData[$index]['settings_pages'] = ['xero' => ['weight' => $weight]];
    }
    $weight++;
  }
}

/**
 * Get contributions for a single contact.
 *
 * @param int $contactID
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 */
function getContactContributions(int $contactID): array {
  $contributions = civicrm_api3('Contribution', 'get', [
    'contact_id' => $contactID,
    'return' => ['contribution_id'],
    'sequential' => TRUE,
  ])['values'];
  return array_column($contributions, 'id');
}

/**
 * Get AccountInvoice data for contributions with errors.
 *
 * @param array $contributions
 *
 * @return array
 *
 * @throws \CiviCRM_API3_Exception
 */
function getErroredInvoicesOfContributions(array $contributions): array {
  return civicrm_api3('AccountInvoice', 'get', [
    'plugin' => 'xero',
    'sequential' => TRUE,
    'contribution_id' => ['IN' => $contributions],
    'error_data' => ['<>' => ''],
  ]);
}

/**
 * Implementation of hook_civicrm_check.
 *
 * Add a check to the status page. Check if there are any account contact or invoice sync errors.
 *
 * @param array $messages
 *
 * @throws \CRM_Core_Exception
 */
function civixero_civicrm_check(array &$messages) {

  try {
    $accountContactErrors = AccountContact::get()
      ->selectRowCount()
      ->addWhere('error_data', 'IS NOT NULL')
      ->addWhere('plugin', '=', 'xero')
      ->addWhere('is_error_resolved', '=', FALSE)
      ->execute()->count();

    $accountInvoiceErrors = AccountInvoice::get()
      ->selectRowCount()
      ->addWhere('error_data', 'IS NOT NULL')
      ->addWhere('plugin', '=', 'xero')
      ->addWhere('is_error_resolved', '=', FALSE)
      ->execute()->count();

    $errorMessage = '';
  }
  catch (UnauthorizedException $e) {
    // Fine - skip the check. We want ACLs to apply but not to
    // error out if they don't have any permissions
    return;
  }

  if ($accountContactErrors > 0) {
    $errorMessage .= 'Found ' . $accountContactErrors . ' contact sync errors. <a href="' . CRM_Utils_System::url('civicrm/accounting/errors/contacts') . '" target="_blank">Click here</a> to resolve them.';
    if ($accountInvoiceErrors > 0) {
      $errorMessage .= '<br><br>';
    }
  }
  if ($accountInvoiceErrors > 0) {
    $errorMessage .= 'Found ' . $accountInvoiceErrors . ' invoice sync errors. <a href="' . CRM_Utils_System::url('civicrm/accounting/errors/invoices') . '" target="_blank">Click here</a> to resolve them.';
  }

  if ($accountInvoiceErrors > 0 || $accountContactErrors > 0) {
    $messages[] = new CRM_Utils_Check_Message(
      'civixero_sync_errors',
      $errorMessage,
      'Xero Sync Errors',
      LogLevel::ERROR,
      'fa-refresh'
    );
  }

  $clientID = Civi::settings()->get('xero_client_id');
  $clientSecret = Civi::settings()->get('xero_client_secret');
  $accessTokenData = Civi::settings()->get('xero_access_token');
  if (!$clientID || !$clientSecret) {
    $messages[] = new CRM_Utils_Check_Message(
      'civixero_client_required',
      ts('Please configure a Client ID and Client Secret from your Xero app.'),
      ts('Missing Xero App Details'),
      LogLevel::WARNING,
      'fa-flag'
    );
  }
  elseif (empty($accessTokenData['access_token'])) {
    $messages[] = new CRM_Utils_Check_Message(
      'civixero_authorization_required',
      ts('Please Authorize with Xero to enable a connection.'),
      ts('Xero Authorization Required'),
      LogLevel::WARNING,
      'fa-flag'
    );
  }
  elseif (isset($accessTokenData['expires']) && ($accessTokenData['expires'] <= time())) {
    $messages[] = new CRM_Utils_Check_Message(
      'civixero_authorization_required',
      ts('Xero access token has expired. You need to re-authorize with Xero to re-enable the connection.'),
      ts('Xero Authorization Required'),
      LogLevel::CRITICAL,
      'fa-flag'
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
  if ($pageName !== 'CRM_Contact_Page_View_Summary' || !CRM_Core_Permission::check('view all contacts')) {
    return;
  }

  if (($contactID = $page->getVar('_contactId')) !== FALSE) {

    CRM_Civixero_Page_Inline_ContactSyncStatus::addContactSyncStatusBlock($page, $contactID);
    CRM_Civixero_Page_Inline_ContactSyncLink::addContactSyncLinkBlock($page, $contactID);
    CRM_Civixero_Page_Inline_ContactSyncErrors::addContactSyncErrorsBlock($page, $contactID);
    CRM_Civixero_Page_Inline_InvoiceSyncErrors::addInvoiceSyncErrorsBlock($page, $contactID);

    CRM_Core_Region::instance('contact-basic-info-left')->add([
      'template' => 'CRM/Civixero/ContactSyncBlock.tpl',
    ]);

  }

  CRM_Core_Resources::singleton()->addScriptFile('nz.co.fuzion.civixero', 'js/civixero_errors.js');
}

/**
 * Get available connectors.
 *
 * @return array
 */
function _civixero_get_connectors(): array {
  static $connectors = [];
  if (empty($connectors)) {
    try {
      $connectors = civicrm_api3('connector', 'get', ['connector_type_id' => 'CiviXero']);
      $connectors = $connectors['values'];
    }
    catch (CRM_Core_Exception $e) {
      $connectors = [0 => 0];
    }
  }
  return $connectors;
}

/**
 * @param string $objectName
 * @param array $headers
 * @param array|null $values
 *
 * @noinspection PhpUnusedParameterInspection
 */
function civixero_civicrm_searchColumns(string $objectName, array &$headers, ?array &$values) {
  if ($objectName === 'contribution') {
    foreach ($values as &$value) {
      try {
        $invoiceID = civicrm_api3('AccountInvoice', 'getvalue', [
          'plugin' => 'xero',
          'contribution_id' => $value['contribution_id'],
          'return' => 'accounts_invoice_id',
        ]);
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
function civixero_civicrm_mapAccountsData(array &$accountsData, string $entity, string $plugin) {
  if ($plugin !== 'xero' || $entity !== 'contact') {
    return;
  }
  $accountsData['civicrm_formatted'] = [];
  $mappedFields = [
    'Name' => 'display_name',
    'FirstName' => 'first_name',
    'LastName' => 'last_name',
    'EmailAddress' => 'email',
  ];
  foreach ($mappedFields as $xeroField => $civicrmField) {
    if (!empty($accountsData[$xeroField])) {
      $accountsData['civicrm_formatted'][$civicrmField] = $accountsData[$xeroField];
    }
  }

  if (is_array($accountsData['Addresses']) && is_array($accountsData['Addresses']['Address'])) {
    foreach ($accountsData['Addresses']['Address'] as $address) {
      if (count($address) > 1) {
        $addressMappedFields = [
          'AddressLine1' => 'street_address',
          'City' => 'city',
          'PostalCode' => 'postal_code',
        ];
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
        $addressMappedFields = [
          'PhoneNumber' => 'phone',
        ];
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
 *
 * @param $plugins
 */
function civixero_civicrm_accountsync_plugins(&$plugins) {
  $plugins[] = 'xero';
}

/**
 * Implements hook_civicrm_contactSummaryBlocks().
 *
 * @link https://github.com/civicrm/org.civicrm.contactlayout
 *
 * @param $blocks
 */
function civixero_civicrm_contactSummaryBlocks(&$blocks) {
  $blocks += [
    'civixeroblock' => [
      'title' => ts('Civi Xero'),
      'blocks' => [],
    ],
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
