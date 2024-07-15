# CiviXero

Synchronisation [CiviCRM](https://civicrm.org) and [Xero](https://xero.com) for financial transactions and contacts.

This extension requires the [AccountSync extension](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync).

It sets up scheduled jobs that synchronize Xero contacts and invoices with CiviCRM contacts and invoices.

## Questions

### What is synced?

#### Invoice Pull Job:

This pulls invoices from Xero and puts them in the account_invoice table.
* If invoice was pushed *from* CiviCRM it is automatically linked to the CiviCRM contribution and status will be updated per settings.
* If invoice was created in Xero it will not be linked to a CiviCRM contribution. If you want it to sync you need to manually add a contribution ID to the record in the account_invoice table.

#### Invoice Push Job:

This pushes invoices to Xero based on the configured settings.

Invoices that were created in CiviCRM and then pushed to Xero will automatically be linked and can be synced both ways.

#### Contact Pull Job:

This pulls contacts from Xero and puts them in the account_contact table.
* If contact was pushed *from* CiviCRM it is automatically linked to the CiviCRM contact and will be updated per settings.
* If contact was created in Xero in will not be linked to a CiviCRM contact until it is manually matched via "Administer->CiviContribute->Xero->Synchronize contacts".

#### Contact Push Job:

This pushes contacts to Xero based on the configured settings.

Contacts that were created in CiviCRM and then pushed to Xero will automatically be linked and can be synced both ways.

