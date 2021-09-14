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
function _civicrm_api3_civixero_invoicepull_spec(&$spec) {
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
  $spec['invoice_number'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'title' => E::ts('Invoice Number'),
    'description' => E::ts('The (Optional) Xero Invoice number to pull (eg. IN-0624'),
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
function civicrm_api3_civixero_invoicepull($params) {
  $xero = new CRM_Civixero_Invoice($params);
  $result = $xero->pull($params);
  return civicrm_api3_create_success($result, $params);
}
