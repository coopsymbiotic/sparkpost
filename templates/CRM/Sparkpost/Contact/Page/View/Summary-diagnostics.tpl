{crmScope extensionKey="sparkpost"}
  <div class="crm-block crm-content-block">
    {foreach from=$sparkpost_emails item=e}
      {capture assign=sparkpostUrl}{crmURL p='civicrm/sparkpost/events' q="email_id=`$e.id`"}{/capture}
      <p><a class="crm-popup" href="{$sparkpostUrl}">{ts 1=$e.email}View delivery history for %1{/ts}</a>
    {/foreach}
  </div>
{/crmScope}
