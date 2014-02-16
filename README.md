nz.co.fuzion.civixero
=====================

Synchronisation between CiviCRM &amp; Xero

This extension requires the extension https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync to work.

It sets up scheduled jobs that synchronize Xero contacts and invoices with CiviCRM contacts and invoices.

Interaction with this module is primarily by API and it creates scheduled jobs to run those API. These jobs may not auto-create in CiviCRM versions prior to 4.4 or 4.2.16.

SETUP
You need a Xero api key

Log into https://api.xero.com/Application?redirectCount=0

Choose My Applications
<img src='https://raw2.github.com/eileenmcnaughton/nz.co.fuzion.civixero/master/docs/images/create_application.png'>

Follow the Xero instructions to set up a .cer and public key

http://blog.xero.com/developer/api-overview/setup-an-application/#private-apps

You will then be able to access the Xero credentials you need for CiviCRM

<img src='https://raw2.github.com/eileenmcnaughton/nz.co.fuzion.civixero/tree/master/docs/images/credentials.png'>

You then need to enter these keys into the Xero Settings page per Xero Settings

<img src='https://raw2.github.com/eileenmcnaughton/nz.co.fuzion.civixero/tree/master/docs/images/xero_settings.png'>

On this page you should also define which edit and create actions should trigger contacts / invoices to be created / edited in Xero


