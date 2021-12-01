<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Sparkpost_Upgrader extends CRM_Sparkpost_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Database upgrade for version 1.1.
   *
   * Provides a setting name less prone to naming collisions.
   *
   * @return TRUE on success
   * @throws Exception
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
   * Database upgrade for version 1.1.
   *
   * Encrypt SparkPost API key.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1101() {
    $this->ctx->log->info('Applying update 1101 - Encrypt SparkPost API key');

    $key = civicrm_api3('Setting', 'getvalue', array(
      'name' => 'sparkpost_apiKey',
    ));
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

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
