{capture assign=smtpURL}{crmURL p='civicrm/admin/setting/smtp' q='reset=1'}{/capture}

<div class="crm-block crm-form-block crm-sparkpost-form-block">
    <div id="sparkpost" class="mailoption">
        <fieldset>
            <legend>{ts}SparkPost Configuration{/ts}</legend>
            <table class="form-layout-compressed">
                <tr class="crm-sparkpost-form-block-sparkpost_apiKey">
                    <td class="label">{$form.sparkpost_apiKey.label}</td>
                    <td>{$form.sparkpost_apiKey.html}<br  />
                        <span class="description">{ts}You can create API keys at:{/ts}
                            <a href="https://app.sparkpost.com/account/api-keys" target="_blank">https://app.sparkpost.com/account/api-keys</a>
                        {ts}or if using an EU-hosted platform:{/ts}
                            <a href="https://app.eu.sparkpost.com/account/api-keys" target="_blank">https://app.eu.sparkpost.com/account/api-keys</a>
                        </span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_ipPool">
                    <td class="label">{$form.sparkpost_ipPool.label}</td>
                    <td>{$form.sparkpost_ipPool.html}<br  />
                        <span class="description">{ts}Only used if you have one or more dedicated IP addresses at SparkPost.{/ts}</span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_useBackupMailer">
                    <td class="label">{$form.sparkpost_useBackupMailer.label}</td>
                    <td>{$form.sparkpost_useBackupMailer.html}<br  />
                        <span class="description">{ts 1=$smtpURL}You can define a backup mailer <a href='%1'>here</a>.{/ts}
                            {ts}It will be used if Sparkpost cannot send emails (unverified sending domain, sending limits exceeded, ...).{/ts}
                        </span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_host">
                    <td class="label">{$form.sparkpost_host.label}</td>
                    <td>{$form.sparkpost_host.html}<br  />
                        <span class="description">{ts}Select the host. If you are using an EU-hosted platform you will need an <a href="https://app.eu.sparkpost.com">European account</a>.{/ts}
                        </span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_customCallbackUrl">
                    <td class="label">{$form.sparkpost_customCallbackUrl.label}</td>
                    <td>{$form.sparkpost_customCallbackUrl.html}<br  />
                        <span class="description">{ts 1=$smtpURL}A custom callback URL is useful when your site is behind a proxy (like CiviProxy). Leave this blank to use the default URL.{/ts}
                        </span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_sending_quota">
                    <td class="label">{$form.sparkpost_sending_quota.label}</td>
                    <td>{$form.sparkpost_sending_quota.html}<br  />
                        <span class="description">{ts}Monthly sending quota. This is checked against Sparkpost metrics. Above this number, a critical Status Check will be displayed, but it will not stop sending emails. It can be useful for monitoring.{/ts}
                        </span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_sending_quota_alert">
                    <td class="label">{$form.sparkpost_sending_quota_alert.label}</td>
                    <td>{$form.sparkpost_sending_quota_alert.html}<br/>
                        <span class="description">{ts}Above this number, a critical Status Check will be displayed. For now, it will not stop sending emails. It can be useful for monitoring.{/ts}</span>
                    </td>
                </tr>
                <tr class="crm-sparkpost-form-block-sparkpost_bounce_rate">
                    <td class="label">{$form.sparkpost_bounce_rate.label}</td>
                    <td>{$form.sparkpost_bounce_rate.html}<br  />
                        <span class="description">{ts}This is checked against Sparkpost metrics. Above this number, a critical Status Check will be displayed, but it will not stop sending emails. It can be useful for monitoring.{/ts}
                        </span>
                    </td>
                </tr>
            </table>

            <p>{ts 1=$sparkpost_test_email}Test emails will be sent to: %1{/ts}</p>
        </fieldset>
    </div>
    <div class="spacer"></div>
    <div class="crm-submit-buttons">
        {include file="CRM/common/formButtons.tpl"}
    </div>
</div>
