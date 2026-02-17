<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Sparkpost_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Provides a setting name less prone to naming collisions.
   */
  public function upgrade_1100() {
    $this->ctx->log->info('Applying update 1100 - Rename apiKey setting');
    $setting = new CRM_Core_BAO_Setting();
    $setting->name = 'apiKey';
    $setting->find();
    if ($setting->count() > 1) {
      CRM_Core_Error::fatal('Could not update setting name due to naming collisions; more than one setting is named apiKey.');
      return FALSE;
    }
    while ($setting->fetch()) {
      $setting->name = 'sparkpost_apiKey';
      $setting->save();
    }

    return TRUE;
  }

  /**
   * Encrypt SparkPost API key.
   */
  public function upgrade_1101() {
    $this->ctx->log->info('Applying update 1101 - Encrypt SparkPost API key');

    $key = civicrm_api3('Setting', 'getvalue', ['name' => 'sparkpost_apiKey']);
    // don't try to encrypt the key if none has been set
    if ($key) {
      // The setSettings function will encrypt before saving
      CRM_Sparkpost::setSetting('sparkpost_apiKey', $key);
    }
    return TRUE;
  }

  public function upgrade_1102() {
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailing set name = %1 WHERE name = %2', [
      1 => ['Sparkpost Transactional Emails', 'String'],
      2 => ['Transaction Emails Sparkpost', 'String'],
    ]);

    return TRUE;
  }

  public function upgrade_1103() {
    $this->ctx->log->info('Applying update 1102 - fix mailbox full classification');

    // fix classification for older bounce
    CRM_Core_DAO::executeQuery("
UPDATE civicrm_mailing_event_bounce SET bounce_type_id = 8
WHERE (bounce_reason LIKE '%is full%' OR bounce_reason LIKE '%over quota%')");

    $datetime = new \DateTime();
    $datetime->modify('-6 months');
    $maxDate = $datetime->format('Y-m-d');

    // disable email that have more than 3 quota bounce
    $today = date('Y-m-d');
    $dao =  CRM_Core_DAO::executeQuery("
SELECT e.id as id
FROM civicrm_mailing_event_bounce meb
  INNER JOIN civicrm_mailing_event_queue meq ON meb.event_queue_id = meq.id
  INNER JOIN civicrm_email e ON e.id = meq.email_id
WHERE bounce_type_id = 8 AND e.on_hold = 0
GROUP BY email_id HAVING count(*) > 3 AND max(meb.time_stamp) > {$maxDate}");
    while ($dao->fetch()) {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_email SET on_hold = 1, hold_date = %1 WHERE id = %2", [
        1 => [$today, 'String'],
        2 => [$dao->id, 'Integer']
      ]);
    }

    return TRUE;
  }

}
