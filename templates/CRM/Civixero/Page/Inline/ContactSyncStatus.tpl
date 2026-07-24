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
          {if $canPushXero_xero}
            &nbsp;<a href='#' id='xero-push-now' data-contact-id={$contactID_xero} data-connector-id={$connectorID_xero}>{ts}Push Now{/ts}</a>
          {/if}
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
    {if $syncStatus_xero == 2 && $canPushXero_xero}
    {literal}
      <script type="text/javascript">
        CRM.$('#xero-push-now').click(function(event) {
          event.preventDefault();
          var $link = CRM.$(this);
          $link.text('{/literal}{ts escape="js"}Pushing...{/ts}{literal}').off('click');
          CRM.api3('Civixero', 'contactpush', {
            'contact_id' : $link.data('contact-id'),
            'connector_id' : $link.data('connector-id'),
          }).done(function(result) {
            if (result.hasOwnProperty('error_message')) {
              $link.replaceWith(result.error_message);
            }
            else {
              $link.closest('.crm-content').text('{/literal}{ts}Contact is synced with Xero{/ts}{literal}');
            }
          });
        });
      </script>
    {/literal}
    {/if}
</div>
