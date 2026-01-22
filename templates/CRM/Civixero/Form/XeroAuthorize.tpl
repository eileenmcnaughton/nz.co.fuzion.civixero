<h3>{ts}Xero client authentication details{/ts}</h3>
<div class="crm-section">
  <div class="label">{$form.xero_client_id.label}</div>
  <div class="content">{$form.xero_client_id.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-section">
  <div class="label">{$form.xero_client_secret.label}</div>
  <div class="content">{$form.xero_client_secret.html}</div>
  <div class="clear"></div>
</div>
{if !empty($accesstoken_expiry_date)}
<div class="crm-section">
  <div class="label">{ts}Access token expiry date{/ts}</div>
  <div class="content">{$accesstoken_expiry_date|crmDate}</div>
  <p class="help">The access token is valid for 30 minutes. It is automatically renewed.</p>
</div>
{/if}

<h3>{ts}Status{/ts}</h3>
<div class="crm-section">
  {$statusIcon}  {$statusMessage}
</div>

<h3>{ts}Sync Status{/ts}</h3>
<p>{ts}Data is synchronized via scheduled jobs. No data will be synchronized until you enable one or more jobs.{/ts}</p>
<ul>
{foreach from=$xeroJobs key=description item=data}
  <li>{$description}: <strong>{if $data.active}{ts}Enabled{/ts}{else}{ts}Disabled{/ts}{/if}</strong></li>
{/foreach}
</ul>

{* FOOTER *}
<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
