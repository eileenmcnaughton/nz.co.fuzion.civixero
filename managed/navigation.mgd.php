<?php
use CRM_Civixero_ExtensionUtil as E;

$entities = [
  [
    'name' => 'Navigation_Xero',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => 'Xero',
        'name' => 'Xero',
        'url' => NULL,
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM data',
          'administer CiviCRM system',
        ],
        'permission_operator' => 'OR',
        'parent_id.name' => 'CiviContribute',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 19,
      ],
    ],
  ],
  [
    'name' => 'Navigation_Xero_Errors',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Errors'),
        'name' => 'XeroErrors',
        'url' => NULL,
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'OR',
        'parent_id.name' => 'Xero',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 19,
      ],
    ],
  ],
  [
    'name' => 'Navigation_Xero_Invoice_Errors',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Invoice Errors'),
        'name' => 'XeroInvoiceErrors',
        'url' => 'civicrm/accounting/errors/invoices',
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'OR',
        'parent_id.name' => 'XeroErrors',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 19,
      ],
    ],
  ],
  [
    'name' => 'Navigation_Xero_Contact_Errors',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Contact Errors'),
        'name' => 'XeroContactErrors',
        'url' => 'civicrm/accounting/errors/contacts',
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'OR',
        'parent_id.name' => 'XeroErrors',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 19,
      ],
    ],
  ],
  [
    'name' => 'Navigation_Xero_Navigation_Xero_Settings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Settings'),
        'name' => 'Xero Settings',
        'url' => 'civicrm/admin/setting/xero',
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Xero',
        'is_active' => TRUE,
        'has_separator' => 0,
      ],
    ],
  ],
  [
    'name' => 'Navigation_Xero_Synchronize_Contacts',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Contact Syncronization'),
        'name' => 'Xero Contact Syncronization',
        'url' => 'civicrm/a/#/accounts/contact/sync',
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Xero',
        'is_active' => TRUE,
        'has_separator' => 0,
      ],
    ],
  ],
];
$connectors = _civixero_get_connectors();
foreach ($connectors as $connectorID => $details) {
  $entities[] = [
    'name' => 'Xero Authorize ' . $details['name'],
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Authorize') . ' ' . $details['name'],
        'name' => 'Xero Authorize ' . $details['name'],
        'url' => 'civicrm/xero/authorize?connector_id=' . $connectorID,
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Xero',
        'is_active' => TRUE,
        'has_separator' => 0,
      ],
    ],
  ];
}
return $entities;
