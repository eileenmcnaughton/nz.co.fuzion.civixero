

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    $(document).ready(function() {
      $('<tr class="crm-contribution-form-block-xero-status"><td class="label">{/literal}{$xero_invoice_label}{literal}</td><td>{/literal}{$xero_invoice_status}{literal}</td></tr>')
        .appendTo($('div.crm-contribution-view-form-block > table.crm-info-panel > tbody'));
    });
  });
</script>

{/literal}
