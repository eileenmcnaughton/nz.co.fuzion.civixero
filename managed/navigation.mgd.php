<?php

return [
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
        'label' => 'Xero Errors',
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
        'label' => 'Xero Invoice Errors',
        'name' => 'XeroInvoiceErrors',
        'url' => NULL,
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
        'label' => 'Xero Settings',
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
];
