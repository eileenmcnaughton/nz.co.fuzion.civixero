<?php

/**
 * Civixero.ContactPull API specification.
 *
 * This is used for documentation and validation.
 *
 * @param array $spec
 *   Description of fields supported by this API call.
 *
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_civixero_itempull_spec(&$spec) {
  $spec['start_date'] = [
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_DATE,
    'name' => 'start_date',
    'title' => 'Sync Start Date',
    'description' => 'date to start pulling from',
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'connector_id',
    'title' => 'Connector ID',
    'description' => 'Connector ID if using nz.co.fuzion.connectors, else 0',
  ];
}

/**
 * Civixero.ContactPull API.
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 *
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civixero_itempull(array $params): array {
  $xero = new CRM_Civixero_Item($params);
  return civicrm_api3_create_success($xero->pull($params));
}

