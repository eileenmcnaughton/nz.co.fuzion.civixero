<?php
use CRM_Civixero_ExtensionUtil as E;

$entities = [
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
        'parent_id.name' => 'Accounts_System',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 10,
      ],
    ],
  ],
];
$connectors = _civixero_get_connectors();
if (empty($connectors)) {
  return $entities;
}
foreach ($connectors as $connectorID => $details) {
  $entities[] = [
    'name' => 'Xero Authorize ' . ($details['name'] ?? ''),
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Xero Authorize') . ' ' . ($details['name'] ?? ''),
        'name' => 'Xero Authorize ' . ($details['name'] ?? ''),
        'url' => 'civicrm/xero/authorize?connector_id=' . $connectorID,
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM system',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Accounts_System',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 11,
      ],
    ],
  ];
}
return $entities;
