# Suppression List

SparkPost supports two types of suppression lists: one (available via the Suppression List API) is specifically for your account, and a global suppression list. SparkPost maintains a global suppression list across all customers

## Remove Email from Suppression List
Once Email reported in Spam Complaints, Hard Bounce, Unsubscribe Requests, sparkpost report this to civicrm and civicrm set on hold flag true.
There is no way to remove email from suppression list from civicrm UI until you logged in to Sparkpost and remove email from suppression list.

To remove email from suppression list, we need to unset on-hold flag on email. On submit, we check Previous and Current value of on_hold flag and call sparkpost api to remove email from suppression list.
