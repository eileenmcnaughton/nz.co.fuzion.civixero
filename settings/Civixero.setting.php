<?php

use CRM_Civixero_ExtensionUtil as E;

return [
  // Removed settings.
  // xero_key, xero_public_certificate.
  'xero_client_id' => [
    'name' => 'xero_client_id',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Client ID',
    'title' => 'Xero Client ID',
    'help_text' => '',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
  ],
  // OAuth 2.0 xero (Client) Secret
  'xero_client_secret' => [
    'name' => 'xero_client_secret',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Client Secret',
    'title' => 'Xero Client Secret',
    'help_text' => '',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
  ],
  // OAuth 2.0, No UI. Retrieved and stored on Authentication/Refresh.
  // Temporary, lifespan 30 mins.
  'xero_access_token_access_token' => [
    'name' => 'xero_access_token_access_token',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Access Token Access token',
    'title' => 'Xero Access Token Access token',
    'help_text' => '',
    // No form element
  ],
  'xero_access_token_refresh_token' => [
    'name' => 'xero_access_token_refresh_token',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Access Token Refresh token',
    'title' => 'Xero Access Token Refresh token',
    'help_text' => '',
    // No form element
  ],
  'xero_access_token_expires' => [
    'name' => 'xero_access_token_expires',
    // Type is really timestamp - I haven't checked if that would work
    // but would be good to make it visible as such
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Access Token Expires',
    'title' => 'Xero Access Token Expires',
    'help_text' => '',
    // No form element
  ],
  // OAuth 2.0. Obtained during Xero authentication.
  'xero_tenant_id' => [
    'name' => 'xero_tenant_id',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Tenant ID (Organization)',
    'title' => 'Xero Tenant ID',
    'help_text' => '',
    // No form element
  ],
  'xero_default_revenue_account' => [
    'name' => 'xero_default_revenue_account',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => 200,
    'title' => 'Xero Default Revenue Account',
    'description' => 'Account to code contributions to',
    'help_text' => 'For more complex rules you will need to add a custom extension',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 50,
    ],
    'settings_pages' => ['xero' => ['weight' => 1]],
  ],
  'xero_invoice_number_prefix' => [
    'name' => 'xero_invoice_number_prefix',
    'type' => 'String',
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Optionally define a string to prefix invoice numbers when pushing to Xero.',
    'title' => 'Xero invoice number prefix',
    'help_text' => '',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 50,
    ],
    'settings_pages' => ['xero' => ['weight' => 2]],
  ],
  'xero_default_invoice_status' => [
    'name' => 'xero_default_invoice_status',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => 'SUBMITTED',
    'title' => 'Xero Default Invoice Status',
    'description' => 'Default Invoice status to push to Xero.',
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'pseudoconstant' => ['callback' => 'CRM_Civixero_Invoice::getInvoiceStatuses'],
    'settings_pages' => ['xero' => ['weight' => 3]],
  ],
  'xero_tax_mode' => [
    'name' => 'xero_tax_mode',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => 'Inclusive',
    'title' => 'Xero Tax mode',
    'description' => 'Are CiviCRM contributions inclusive or exclusive of tax.',
    'help_text' => 'This setting is generally only useful if you track tax in xero but not CiviCRM',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'pseudoconstant' => ['callback' => 'CRM_Civixero_Invoice::getTaxModes'],
    'settings_pages' => ['xero' => ['weight' => 4]],
  ],
  'xero_sync_location_type' => [
    'name' => 'xero_sync_location_type',
    'type' => 'Int',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => 0,
    'title' => 'CiviCRM location type to sync to Xero (will fallback to - Primary - if location type is empty)',
    'description' => 'Select the preferred location type to sync to Xero. Will fallback to "Primary" if not set.',
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'pseudoconstant' => ['callback' => 'CRM_Civixero_Contact::getLocationTypes'],
    'settings_pages' => ['xero' => ['weight' => 4]],
  ],
  'xero_oauth_rate_exceeded' => [
    'name' => 'xero_oauth_rate_exceeded',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => '',
    'title' => 'Xero OAuth Rate Exceeded',
    'description' => 'Timestamp when OAuth Rate was exceeded. Cleared after one hour',
  ],
];
