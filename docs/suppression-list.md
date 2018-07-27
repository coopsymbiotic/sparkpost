# Suppression List
SparkPost supports two types of suppression lists: one (available via the Suppression List API) is specific to your account, and a global suppression list across all it's customers.

## Adding email addresses to the suppression list
Both Sparkpost and CiviCRM are actively managing suppresion lists. Email addresses are automatically added to the suppression list on delivery failure events such as hard bounces (invalid email address, email rejected as spam, etc) and after a set number of soft bounces (mailbox full, out of office message, etc).

Email addresses on the suppression list are identified with the On Hold flag in CiviCRM. You can get a list of all such flagged email addresses by using the Advanced Search screen.

## Removing email addresses from the suppression list
To remove an email address from the suppression list, just clear the on-hold flag in CiviCRM. On submiting this change, the Sparkpost CiviCRM extension will then call the Sparkpost API to remove this email address from your account-specific suppression list.

In order to keep good reputation as an email sender, and not have your emails identified as spam, it is very important that you only remove email addresses from the suppresion list if you know these email addresses are both valid and will not reject your emails as spam.

For this reason we did not provide a tool to bulk-remove email addresses from the suppression list.
