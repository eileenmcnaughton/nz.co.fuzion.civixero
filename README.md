# nz.co.fuzion.civixero

Synchronisation [CiviCRM](https://civicrm.org) and [Xero](https://xero.com) for financial transactions and contacts.

This extension requires the [AccountSync extension](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync).

It sets up scheduled jobs that synchronize Xero contacts and invoices with CiviCRM contacts and invoices.

Interaction with this module is primarily by API and it creates scheduled jobs to run those API.

## Requirements

* https://github.com/civicrm/civicrm-core/pull/24928 - Add html_attributes to API4 Setting.
