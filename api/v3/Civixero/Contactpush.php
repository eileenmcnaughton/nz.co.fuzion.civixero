<?php

/**
 * Civixero.ContactPull API specification.
 *
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_civixero_contactpush_spec(&$spec) {
  $spec['start_date'] = [
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_DATE,
    'name' => 'start_date',
    'title' => 'Sync Start Date',
    'description' => 'date to start pushing from',
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'connector_id',
    'title' => 'Connector ID',
    'description' => 'Connector ID if using nz.co.fuzion.connectors, else 0',
  ];
  $spec['contact_id'] = [
    'name' => 'contact_id',
    'title' => 'contact ID',
    'description' => 'ID of the CiviCRM contact',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_INT,
  ];
}

/**
 * Civixero.ContactPush API.
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor.
 *
 * @throws API_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civixero_contactpush(array $params): array {
  $options = _civicrm_api3_get_options_from_params($params);
  $xero = new CRM_Civixero_Contact();
  $result = $xero->push($params, $options['limit']);
  return civicrm_api3_create_success(['contactIDspushed' => $result], $params);
}

