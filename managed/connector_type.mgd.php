<?php

$entities = [];
if (civixero_is_extension_installed('nz.co.fuzion.connectors')) {
  $entities[] = [
    'name' => 'CiviXero connector Type',
    'entity' => 'ConnectorType',
    'module' => 'nz.co.fuzion.civixero',
    'params' => [
      'name' => 'CiviXero',
      'description' => 'CiviXero connector information',
      'module' => 'accountsync',
      'function' => 'credentials',
      'plugin' => 'xero',
      'field1_label' => 'Xero Client Id',
      'field2_label' => 'Xero Secret Id',
      'field3_label' => 'Xero Tenant Id',
      'field5_label' => 'Settings',
      'version' => 3,
    ],
  ];
}
return $entities;
