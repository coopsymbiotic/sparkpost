{crmScope extensionKey="sparkpost"}
  {if $sparkpost_events}
    {foreach from=$sparkpost_events item=event}
      <div style="padding-bottom: 2em;">
        <div><span title="{$event.event_id}">{$event.timestamp}</span> - {$event.type}</div>
        <div>{ts}From:{/ts} {$event.friendly_from}</div>
        <div>{$event.subject}</div>
        {if $event.raw_reason}
          <div>{$event.raw_reason}</div>
        {/if}
      </div>
    {/foreach}
  {else}
    <p>{ts}No recent events were found for this email.{/ts}</p>
  {/if}
{/crmScope}
