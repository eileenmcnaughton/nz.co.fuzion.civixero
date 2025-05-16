<?php

use CRM_Civixero_ExtensionUtil as E;

/**
 * Civixero.ContactPull API specification.
 *
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_civixero_contactpull_spec(&$spec) {
  $spec['start_date'] = [
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_DATE,
    'name' => 'start_date',
    'title' => E::ts('Sync Start Date'),
    'description' => E::ts('date to start pulling from (default "yesterday")'),
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'connector_id',
    'title' => E::ts('Connector ID'),
    'description' => E::ts('Connector ID if using nz.co.fuzion.connectors, else 0'),
  ];
  $spec['xero_contact_id'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'xero_contact_id',
    'title' => E::ts('Xero Contact ID'),
    'description' => E::ts('Specify Xero Contact UUID to retrieve a single contact record'),
  ];
}

/**
 * Civixero.ContactPull API.
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civixero_contactpull($params) {
  $xero = new CRM_Civixero_Contact($params);
  $xero->pullUsingApi4($params);
  return civicrm_api3_create_success(1, $params, 'Civixero', 'contactpull');
}
