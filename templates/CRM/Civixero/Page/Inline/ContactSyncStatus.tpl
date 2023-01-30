<div class="crm-summary-row">
  <div class="crm-label">
      {ts}Xero Sync Status{/ts}
  </div>
  <div class="crm-content">
      {if $syncStatus_xero == 0}
        <a href='#' id='xero-sync' data-contact-id={$contactID_xero}>{ts}Queue Sync to Xero{/ts}</a>
      {elseif $syncStatus_xero == 1}
          {ts}Contact is synced with Xero{/ts}
      {elseif $syncStatus_xero == 2}
          {ts}Contact is queued for sync with Xero{/ts}
      {/if}
  </div>

    {if $syncStatus_xero == 0}
    {literal}
      <script type="text/javascript">
        CRM.$('#xero-sync').click(function(event) {
          event.preventDefault();
          CRM.api3('account_contact', 'create',{
            'contact_id' : CRM.$(this).data('contact-id'),
            'plugin' : 'xero',
            'accounts_needs_update' : 1,
          }).done(function(result) {
            if (result.hasOwnProperty('error_message')) {
              CRM.$('#xero-sync').replaceWith(result.error_message);
            }
            else {
              CRM.$('#xero-sync').replaceWith('{/literal}{ts}Contact is queued for sync with Xero{/ts}{literal}');
            }
          });
        });
      </script>

    {/literal}
    {/if}
</div>
