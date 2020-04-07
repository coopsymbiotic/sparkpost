{crmScope extensionKey="sparkpost"}
  <h3>{ts}Errors from the email delivery provider{/ts}</h3>

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
    <p>{ts}No recent events were found for this email. Our provider only keeps a few weeks of data.{/ts}</p>
  {/if}

  <h3>{ts}CiviMail errors{/ts}</h3>

  {if $civimail_errors}
    {foreach from=$civimail_errors item=event}
      <div style="padding-bottom: 2em;">
        <div>{$event.time_stamp} - {$bounce_type_id.type}</div>
        <div>{ts}From:{/ts} {$event.from}</div>
        <div><a href="#">{$event.subject}</a></div>
        <div>{$event.bounce_reason}</div>
      </div>
    {/foreach}
  {else}
    <p>{ts}No CiviMail errors were found for this email.{/ts}</p>
  {/if}
{/crmScope}
