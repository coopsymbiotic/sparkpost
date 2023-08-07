# SparkPost email extension for CiviCRM (Symbiotic fork)

This is a Coop SymbioTIC fork of the SparkPost extension:  
https://lab.civicrm.org/extensions/sparkpost

We strongly encourage you to use the official extension rather than this one,
unless you are hosted by [Coop SymbioTIC](https://www.symbiotic.coop/en), of course! ;-)

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

## Original README (initially by CiviDesk, now community-maintained)

This extension allows CiviCRM to send emails and process bounces through the SparkPost service.

It was designed to seamlessly integrate in the CiviCRM UI, be fully documented and well maintained, be trivial to install and configure, be nimble and fast and accurately process bounces.
It currently is one of the [Top 10](https://stats.civicrm.org/?tab=sites) most used extensions for CiviCRM.

Full documentation (including installation instructions) can be found at https://docs.civicrm.org/sparkpost.

## Show your support!

Development of this extension was fully self-funded by Cividesk and equated to about 40 hours of work.

You can show your support and appreciation for our work by making a donation at https://www.cividesk.com/pay and indicating 'SparkPost support' as the invoice id.

Suggested donation amounts are _$40 for end-users_, and _$40 per client_ using this extension for service providers. With these suggested amounts, we would need 120 donations just to recoup our development costs. Needless to say we are far from that at the moment!

These donations will fund maintenance and updates for this extension, as well as production of other extensions in the future.

Thanks!
