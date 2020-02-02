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
function _civicrm_api3_civixero_invoicepush_spec(&$spec) {
  $spec['contribution_id'] = [
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'contribution_id',
    'title' => 'Contribution ID',
    'description' => 'contribution id (optional, overrides needs_update flag)',
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'title' => 'Connector if defined or 0 for site wide',
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
 * @throws API_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civixero_invoicepush($params) {
  $options = _civicrm_api3_get_options_from_params($params);

  $xero = new CRM_Civixero_Invoice($params);
  $xero->push($params, $options['limit']);
}

