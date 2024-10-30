{if $hasInvoiceErrors_xero}
    <div class="crm-summary-row">
        <div class="crm-label">
            Invoice Sync Errors with Xero
        </div>
        <div class="crm-content">
          {$erroredInvoices_xero} Contribution <span class='error'>not synced</span> with Xero
        </div>
    </div>
{/if}
