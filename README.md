nz.co.fuzion.civixero
=====================

Synchronisation between CiviCRM &amp; Xero

This extension requires the extension https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync to work.

It sets up scheduled jobs that synchronize Xero contacts and invoices with CiviCRM contacts and invoices.

Interaction with this module is primarily by API and it creates scheduled jobs to run those API.

SETUP EXTENSIONS

In the server in the sites, extensions folder in a terminal window you can run the command 
git clone https://github.com/eileenmcnaughton/nz.co.fuzion.civixero.git 
and the same for account sync
git clone https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync.git
then you will have the extensions added to the site.

To use these extensions on the site, on the Civi menu on the site go to administer - customise data and screens - manage extensions. There you should install CiviXero and Account Sync.

SETUP XERO KEYS

Log into https://api.xero.com/Application?redirectCount=0

Choose My Applications, Add application
<img src='https://github.com/kayliefz/nz.co.fuzion.civixero/blob/master/docs/images/Add_new_App.png'>

Follow the Xero instructions to set up a .cer and public key

http://developer.xero.com/documentation/getting-started/private-applications/

This involves running the following 3 commands in terminal if you are a mac user

1 - openssl genrsa -out privatekey.pem 1024

2 - openssl req -new -x509 -key privatekey.pem -out publickey.cer -days 1825

3 - openssl pkcs12 -export -out public_privatekey.pfx -inkey privatekey.pem -in publickey.cer

from http://developer.xero.com/documentation/advanced-docs/public-private-keypair/

Either copy and paste or download the .cer (publickey) file and put into the Xero new app.

<img src='https://github.com/kayliefz/nz.co.fuzion.civixero/blob/master/docs/images/New_App_filled_out.png'>

Save the new app and you should see the app details page in Xero.

<img src='https://github.com/kayliefz/nz.co.fuzion.civixero/blob/master/docs/images/App_Details.png'>

SETUP IN CIVICRM

You then need to enter the Xero keys (seen on the app details page) into the Xero Settings page in CiviCRM (Administer>Xero>Xero Settings)

Enter the relevant keys and paths
Consumer Key = Xero Key
Consumer Secret = Xero Secret

<img src='https://raw.githubusercontent.com/eileenmcnaughton/nz.co.fuzion.civixero/master/docs/images/xero_settings.png'>

On this page you should also define which edit and create actions should trigger contacts / invoices to be created / edited in Xero

(Due to current civi bug, disable and reenable civi logging if you are using db logging)

Once installed you interact with CiviXero via the scheduled jobs page and the api. Matched contacts should show links on their contact summary screen and matched contributions should show links on the invoices


CiviCRM tracks synchronisation in the civicrm_account_contact table - to populate this from xero run the api command civixero contactpull with a start_date - e.g '1 year ago'

e.g
drush cvapi civixero.contactpull start_date=0



  You can create a link from Xero back to your site by going to settings/ Custom links and adding a link pointing to
  
  https://YOURSITE/civicrm/contact/view?reset=1&cid={!CONTACTCODE}
