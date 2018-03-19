<div class="crm-content-block crm-block">
    {include file="CRM/common/pager.tpl" location="top"}
    {include file='CRM/common/jsortable.tpl'}
    <div id="claim_level-wrapper" class="dataTables_wrapper">
        <table id="claim_level-table" class="display">
            <thead>
            <tr>
                <th>ID</th>
                {if $errorsfor == 'contact'}
                    <th id="nosort">{ts}Contact{/ts}</th>
                    <th id="nosort">{ts}Account Contact Id{/ts}</th>
                {else}
                    <th id="nosort" width="100">{ts}Contribution{/ts}</th>
                    <th id="nosort">{ts}Account Invoice Id{/ts}</th>
                {/if}
                <th id="nosort">{ts}Error Data{/ts}</th>
                <th>{ts}Last Sync Date{/ts}</th>
            </tr>
            </thead>
            <tbody>
            {assign var="rowClass" value="odd-row"}
            {assign var="rowCount" value=0}
            {foreach from=$syncErrors key=errorId item=syncerror}
                {assign var="rowCount" value=$rowCount+1}
                <tr id="row{$rowCount}" class="{cycle values="odd,even"}">
                    <td>{ $syncerror.id }</td>
                    {if $errorsfor == 'contact'}
                        <td>
                            <a href="{crmURL p='civicrm/contact/view' q="action=view&reset=1&cid=`$syncerror.contact_id`"}" target="_blank">{ $syncerror.contactname }</a>
                        </td>
                        <td>{ $syncerror.accounts_contact_id }</td>
                    {else}
                        <td>
                            <a href="{crmURL p='civicrm/contact/view/contribution' q="action=view&reset=1&id=`$syncerror.contribution_id`"}" target="_blank">#{ $syncerror.contribution_id }</a><br>
                            From: <a href="{crmURL p='civicrm/contact/view' q="action=view&reset=1&cid=`$syncerror.contact_id`"}" target="_blank">{ $syncerror.contactname }</a>
                        </td>
                        <td>{ $syncerror.accounts_invoice_id }</td>
                    {/if}
                    <td>
                        <ul class="syncErrorsList">
                            {foreach from=$syncerror.error_data key=eid item=error}
                                <li>{ $error }</li>
                            {/foreach}
                        </ul>
                    </td>

                    <td>{ $syncerror.last_sync_date|crmDate }
                    </td>
                </tr>
                {if $rowClass eq "odd-row"}
                    {assign var="rowClass" value="even-row"}
                {else}
                    {assign var="rowClass" value="odd-row"}
                {/if}
            {/foreach}
            </tbody>
        </table>
    </div>
    {include file="CRM/common/pager.tpl" location="bottom"}
</div>