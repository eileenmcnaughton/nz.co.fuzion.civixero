<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return [
  0 => [
    'name' => 'CiviXero Contact Push Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'CiviXero Contact Push Job',
      'description' => 'Push updated contacts to Xero',
      'api_entity' => 'Civixero',
      'api_action' => 'contactpush',
      'run_frequency' => 'Always',
      'parameters' => 'plugin=xero',
      'update' => 'never',
    ],
  ],
  1 => [
    'name' => 'CiviXero Contact Pull Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'CiviXero Contact Pull Job',
      'description' => 'Pull updated contacts from Xero',
      'api_entity' => 'Civixero',
      'api_action' => 'contactpull',
      'run_frequency' => 'Always',
      'parameters' => "plugin=xero\nstart_date=yesterday",
      'update' => 'never',
    ],
  ],
  2 => [
    'name' => 'CiviXero Invoice Push Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'CiviXero Invoice Push Job',
      'description' => 'Push updated invoices from Xero',
      'api_entity' => 'Civixero',
      'api_action' => 'invoicepush',
      'run_frequency' => 'Always',
      'parameters' => 'plugin=xero',
      'update' => 'never',
    ],
  ],
  3 => [
    'name' => 'CiviXero Invoice Pull Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'CiviXero Invoice Pull Job',
      'description' => 'Pull updated invoices from Xero',
      'api_entity' => 'Civixero',
      'api_action' => 'invoicepull',
      'run_frequency' => 'Always',
      'parameters' => "plugin=xero\nstart_date=yesterday",
      'update' => 'never',
    ],
  ],
  4 => [
    'name' => 'CiviAccountSync Complete Contributions From Accounts (Xero)',
    'entity' => 'Job',
    'params' =>
      [
        'version' => 3,
        'name' => 'CiviAccountSync Complete Contributions',
        'description' => 'Complete Contributions in CiviCRM where completed in Accounts',
        'api_entity' => 'AccountInvoice',
        'api_action' => 'update_contribution',
        'run_frequency' => 'Always',
        'parameters' => 'plugin=xero
accounts_status_id=1',
        'update' => 'never',
      ],
  ],
  5 => [
    'name' => 'CiviAccountSync Cancel Contributions From Accounts (Xero)',
    'entity' => 'Job',
    'params' =>
      [
        'version' => 3,
        'name' => 'CiviAccountSync Cancel Contributions',
        'description' => 'Cancel Contributions in CiviCRM where cancelled in Accounts',
        'api_entity' => 'AccountInvoice',
        'api_action' => 'update_contribution',
        'run_frequency' => 'Always',
        'parameters' => 'plugin=xero
       accounts_status_id=3',
        'update' => 'never',
      ],
  ],
];
