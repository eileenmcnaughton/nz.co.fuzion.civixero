<?php

$invoice_statuses = [
  'SUBMITTED' => 'Submitted',
  'AUTHORISED' => 'Authorised',
  'DRAFT' => 'Draft',
];

return [
  // Removed settings.
  // xero_key, xero_public_certificate.
  'xero_client_id' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
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
    'quick_form_type' => 'Element',
  ],
  // OAuth 2.0 xero (Client) Secret
  'xero_client_secret' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
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
    'quick_form_type' => 'Element',
  ],
  // OAuth 2.0, No UI. Retrieved and stored on Authentication/Refresh.
  // Temporary, lifespan 30 mins.
  // Stored as serialized array.
  // Can be used to initialize League\OAuth2\Client\Token\AccessToken().
  // Includes refresh_token property so should always be stored even if expired.
  'xero_access_token' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
    'name' => 'xero_access_token',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Xero Access Token',
    'title' => 'Xero Access Token',
    'help_text' => '',
    // No form element
  ],
  // OAuth 2.0. Obtained during Xero authentication.
  'xero_tenant_id' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
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
    'group_name' => 'Xero Settings',
    'group' => 'xero',
    'name' => 'xero_default_revenue_account',
    'type' => 'String',
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => 200,
    'title' => 'Xero Default Revenue Account',
    'description' => 'Account to code contributions to',
    'help_text' => 'For more complex rules you will need to add a custom extension',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
    'quick_form_type' => 'Element',
  ],
  'xero_invoice_number_prefix' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
    'name' => 'xero_invoice_number_prefix',
    'type' => 'String',
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Optionally define a string to prefix invoice numbers when pushing to Xero.',
    'title' => 'Xero invoice number prefix',
    'help_text' => '',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
    'quick_form_type' => 'Element',
  ],
  'xero_default_invoice_status' => [
    'group_name' => 'Xero Settings',
    'group' => 'xero',
    'name' => 'xero_default_invoice_status',
    'type' => 'Array',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => ['SUBMITTED'],
    'title' => 'Xero Default Invoice Status',
    'description' => 'Default Invoice status to push to Xero.',
    'help_text' => '',
    'html_type' => 'Select',
    'quick_form_type' => 'Element',
    'html_attributes' => $invoice_statuses,
  ],
];
