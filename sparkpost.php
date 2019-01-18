<?php
/**
 * This extension allows CiviCRM to send emails and process bounces through
 * the SparkPost service.
 *
 * Copyright (c) 2016 IT Bliss, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * Support: https://github.com/cividesk/com.cividesk.email.sparkpost/issues
 * Contact: info@cividesk.com
 */

require_once 'sparkpost.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function sparkpost_civicrm_config(&$config) {
  _sparkpost_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function sparkpost_civicrm_xmlMenu(&$files) {
  _sparkpost_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function sparkpost_civicrm_install() {
  _sparkpost_civix_civicrm_install();
  // Check dependencies and display error messages
  sparkpost_check_dependencies();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function sparkpost_civicrm_uninstall() {
  return _sparkpost_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function sparkpost_civicrm_enable() {
  return _sparkpost_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function sparkpost_civicrm_disable() {
  return _sparkpost_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function sparkpost_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sparkpost_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function sparkpost_civicrm_managed(&$entities) {
  return _sparkpost_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 *
 * Locate the 'Outbound Email' navigation menu (recursive)
 * Replace the menu label and destination url with our own form
 */
function sparkpost_civicrm_navigationMenu( &$param ) {
  foreach ($param as &$menu) {
    if (CRM_Utils_Array::value('attributes', $menu) &&
      CRM_Utils_Array::value('name', $menu['attributes']) == 'Outbound Email'
    ) {
      $menu['attributes']['url'] = 'civicrm/admin/setting/sparkpost';
      $menu['attributes']['label'] = ts('Outbound Email (SparkPost)');
    }
    if (CRM_Utils_Array::value('child', $menu)) {
      sparkpost_civicrm_navigationMenu($menu['child']);
    }
  }
}

/**
 * Implementation of hook_civicrm_alterContent
 *
 * Replace the link to Outbound Email Settings in the administration console
 */
function sparkpost_civicrm_alterContent( &$content, $context, $tplName, &$object ) {
  if ($tplName == 'CRM/Admin/Page/Admin.tpl') {
    $content = str_replace('civicrm/admin/setting/smtp', 'civicrm/admin/setting/sparkpost', $content);
  }
}

/**
 * Implementation of hook_civicrm_alterMailer
 */
// Don't know why it does not autoload ...
require_once 'Mail/Sparkpost.php';
function sparkpost_civicrm_alterMailer(&$mailer, $driver, $params) {
  $sparkpost = new Mail_Sparkpost($params);
  $sparkpost->setBackupMailer($mailer);
  $mailer = $sparkpost;
}

/**
 * Implementation of hook_civicrm_check (4.6.3+)
 */
function sparkpost_civicrm_check(&$messages) {
  $sparkpost_messages = sparkpost_check_dependencies(FALSE);

  // We need to add the severity (only for 4.7+)
  $version = CRM_Utils_System::version();

  if (version_compare($version, '4.7') >= 0) {
    foreach ($sparkpost_messages as &$message) {
      $message->setLevel(5); // Cannot use \Psr\Log\LogLevel::CRITICAL as this is not in 4.6 code base
    }
  }

  $messages += $sparkpost_messages;
}

/**
 * Checks all dependencies for the extension
 *
 * @returns array  Array with one CRM_Utils_Check_Message object for each unmet dependency
 */
function sparkpost_check_dependencies($display = TRUE) {
  // Note: CRM_Utils_Check_Message exists in 4.4+, but does not include severity attribute
  $messages = array();
  $trailer = ts('This requirement is not met in your current configuration, the extension will not work.');
  // Make sure the PHP version is 5.4+
  if (version_compare(PHP_VERSION, '5.4') < 0) {
    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_phpversion',
      ts('The SparkPost extension requires PHP version 5.4 or higher.') . ' ' . $trailer,
      ts('SparkPost requirements not met')
    );
  }
  // Make sure the curl extension is enabled
  if (!function_exists('curl_version')) {
    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_curlextension',
      ts('The SparkPost extension requires the PHP curl library.') . ' ' . $trailer,
      ts('SparkPost requirements not met')
    );
  }
  // Now display a nice alert for all these messages
  if ($display) {
    foreach ($messages as $message) {
      CRM_Core_Session::setStatus($message->getMessage(), $message->getTitle(), 'error');
    }
  }
  return $messages;
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sparkpost_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sparkpost_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

function sparkpost_log($message) {
  $config = CRM_Core_Config::singleton();
  file_put_contents($config->configAndLogDir . 'sparkpost_log', $message . PHP_EOL, FILE_APPEND);
}


/**
 * Implementation of hook_civicrm_pre
 */
function sparkpost_civicrm_pre( $op, $objectName, $objectId, &$objectRef ) {
  /*
   * When email is updated for contact, check on hold flag is changed?
   * If changed (unchecked) then remove same email from sparkpost suppression list
   */
  global $resetHoldFlag;
  $resetHoldFlag = FALSE;
  if ($objectName == 'Email' && $op == 'edit' && $objectId && empty($objectRef['on_hold'])) {
    $resultEmail = civicrm_api3('Email', 'getsingle', array(
      'id' => $objectId,
    ));
    if ($resultEmail['on_hold'] == 1) {
      $resetHoldFlag = TRUE;
    }
  }
}

/**
 * Implementation of hook_civicrm_post
 */
function sparkpost_civicrm_post( $op, $objectName, $objectId, &$objectRef ) {
  /*
   * Previous on hold flag = 1 and Current on hold flag = 0 then remove from sparkpost suppression list
   */
  global $resetHoldFlag;
  if ($objectName == 'Email' && $op == 'edit' && $resetHoldFlag && !empty($objectRef->email)) {
    try {
      $result = CRM_Sparkpost::call('suppression-list/' . $objectRef->email);
    } catch (Exception $e) {
      // don't show the error message to users
      //CRM_Core_Session::setStatus($e->getMessage(), "Sparkpost error", 'error');
    }
  }
}