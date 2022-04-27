{crmScope key="sparkpost"}
  <div class="help">
    {ts}<strong>Sparkpost is enabled:</strong> please use the "mail()" method for email delivery (required for the Sparkpost extension to work as expected) and we also recommend disabling "allow mails from logged-in contacts", since very often users might have set a personal email on their contact record, which will result in that being used accidentally when sending emails. Sparkpost will only allow sending emails from domains that have been validated.{/ts}
    <a href="{crmURL p="civicrm/admin/setting/sparkpost" q="reset=1"}">{ts}Sparkpost Settings{/ts}</a>
  </div>
{/crmScope}
