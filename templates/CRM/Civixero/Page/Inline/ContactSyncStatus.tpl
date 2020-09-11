{if $syncStatus_xero!= 1}
    <div class="crm-summary-row">
        <div class="crm-label">
            Xero Sync Status
        </div>
        <div class="crm-content">
            {if $syncStatus_xero == 0}
                <a href='#' id='xero-sync' data-contact-id={$contactID_xero}>Queue Sync to Xero</a>
            {elseif $syncStatus_xero == 1}
                Contact is synced with Xero
            {elseif $syncStatus_xero == 2}
                Contact is queued for sync with Xero
            {/if}
        </div>

        {literal}

            <script type="text/javascript">
                cj('#xero-sync').click(function( event) {
                    event.preventDefault();
                    CRM.api('account_contact', 'create',{
                        'contact_id' : cj(this).data('contact-id'),
                        'plugin' : 'xero',
                        'connector_id' : 0,
                        'accounts_needs_update' : 1,
                    });
                    cj(this).replaceWith('Contact is queued for sync with Xero');
                });
            </script>

        {/literal}
    </div>
{/if}
