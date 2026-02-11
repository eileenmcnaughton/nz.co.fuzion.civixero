{if $hasInvoiceErrors_xero}
    <div class="crm-summary-row">
        <div class="crm-label">
            {ts}Invoice Sync Errors with Xero{/ts}
        </div>
        <div class="crm-content">
            {ts count=$erroredInvoices_xero plural='%count Contributions not synced with Xero'}%count Contribution not synced with Xero{/ts}
        </div>
    </div>
{/if}
