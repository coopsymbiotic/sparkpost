# SparkPost email extension for CiviCRM (Symbiotic fork)

This is a Coop SymbioTIC fork of the SparkPost extension (initially developed by CiviDesk):  
https://lab.civicrm.org/extensions/sparkpost

This fork can be found here:  
https://lab.civicrm.org/extensions/sparkpost-symbiotic

Some of the additional features included in this fork:

* Uses the SparkPost PHP library (and Guzzle), instead of direct Curl calls, which seems to improve performance.
* Tracks transactional email bounces (based on work by Veda Consulting: https://github.com/cividesk/com.cividesk.email.sparkpost/pull/22)
* Verify the 'verified sending domain' before sending an email, to provide more helpful errors when sending fails.
* Implements various CiviCRM 'system checks' to display the list of verified sending domains, and domain metrics, under CiviCRM > Administer > System Status.
* Notably a system check will warn when the number of emails sent in the current month are over a certain quota (currently hardcoded to 65000! todo: add a setting).
* Special `sparkpost_bypass` variable that can be used with the `alterMailParams` hook, to use the backup mailer instead (we use this for contacts forms that connect with Gitlab Service Desk)

Finally, this extension does not automatically create the webhook on Sparkpost,
because we use a single webhook on our "router" CiviCRM instance. While
Sparkpost now supports having a webhook per subaccount, our "router" extension
supports things such as using a generic email domain with subaddresses (ex:
`noreply+clientA@symbiotic-notifications.net`).  For more information, see
[SparkPostRouter](https://github.com/coopsymbiotic/coop.symbiotic.sparkpostrouter).

## Documentation

See: https://docs.civicrm.org/sparkpost/en/latest/  (documentation of the official/original extension)

## Support

Please post bug reports in the issue tracker of this project on CiviCRM's Gitlab:  
https://lab.civicrm.org/extensions/sparkpost-symbiotic/issues

While we do our best to provide volunteer support for this extension, please
consider financially contributing to support or development of this extension
if you can.

Commercial support available from Coop SymbioTIC:  
https://www.symbiotic.coop/en

Coop Symbiotic is a worker-owned co-operative based in Canada. We have a strong
experience working with non-profits and CiviCRM. We provide affordable, fast,
turn-key hosting with regular upgrades and proactive monitoring, as well as
custom development and training.
