<?php

return [
  [
    'name' => 'SavedSearch_Xero_Contact_Synchronization_Errors',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Xero_Contact_Synchronization_Errors',
        'label' => 'Xero Contact Synchronization Errors',
        'form_values' => NULL,
        'mapping_id' => NULL,
        'search_custom_id' => NULL,
        'api_entity' => 'AccountContact',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'contact_id.display_name',
            'accounts_contact_id',
            'error_data',
            'last_sync_date',
          ],
          'orderBy' => ['id DESC'],
          'where' => [
            [
              'error_data',
              'NOT LIKE',
              '%error_cleared%',
            ],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
        'expires_date' => NULL,
        'description' => NULL,
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Xero_Contact_Synchronization_Errors_SearchDisplay_Xero_Contact_Synchronization_Errors_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Xero_Contact_Synchronization_Errors_display',
        'label' => 'Xero Contact Synchronization Errors',
        'saved_search_id.name' => 'Xero_Contact_Synchronization_Errors',
        'type' => 'table',
        'settings' => [
          'actions' => TRUE,
          'limit' => 50,
          'classes' => [
            'table',
            'table-striped',
          ],
          'pager' => [],
          'placeholder' => 5,
          'sort' => [],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'dataType' => 'Integer',
              'label' => 'id',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'dataType' => 'String',
              'label' => 'Contact',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'accounts_contact_id',
              'dataType' => 'String',
              'label' => 'accounts_contact_id',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'error_data',
              'dataType' => 'Text',
              'label' => 'Account Error Data',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'last_sync_date',
              'dataType' => 'Timestamp',
              'label' => 'Last Synchronization Date',
              'sortable' => TRUE,
            ],
          ],
        ],
        'acl_bypass' => FALSE,
      ],
    ],
  ],
];