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
use CRM_Sparkpost_ExtensionUtil as E;

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
  CRM_Sparkpost::createTransactionalMailing();
}

/**
 * Implementation of hook_civicrm_postInstall
 */
function sparkpost_civicrm_postInstall() {
  _sparkpost_civix_civicrm_postInstall();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function sparkpost_civicrm_uninstall() {
  _sparkpost_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function sparkpost_civicrm_enable() {
  _sparkpost_civix_civicrm_enable();
  CRM_Sparkpost::createTransactionalMailing();
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
  _sparkpost_civix_civicrm_managed($entities);
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
function sparkpost_civicrm_alterMailer(&$mailer, $driver, $params) {
  require_once 'Mail/sparkpost.php';
  $sparkpost = new Mail_sparkpost($params);
  // $sparkpost->setBackupMailer($mailer);
  $mailer = $sparkpost;
}

/**
 * Implementation of hook_civicrm_check
 */
function sparkpost_civicrm_check(&$messages) {
  CRM_Sparkpost_Utils_Check_SendingDomains::check($messages);
  CRM_Sparkpost_Utils_Check_Metrics::check($messages);
  CRM_Sparkpost_Utils_Check_Environment::check($messages);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sparkpost_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sparkpost_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

function sparkpost_civicrm_alterMailParams(&$params, $context = NULL) {
  // Create meta data for transactional email
  if ($context != 'civimail' && $context != 'flexmailer') {
    $mail = new CRM_Mailing_DAO_Mailing();
    $mail->name = 'Sparkpost Transactional Emails';

    if ($mail->find(TRUE)) {
      if (!empty($params['contact_id'])) {
        $contactId = $params['contact_id'];
      }
      elseif (!empty($params['contactId'])) {
        // Contribution/Event confirmation
        $contactId = $params['contactId'];
      }
      else {
        // As last option from emall address
        $contactId = sparkpost_targetContactId($params['toEmail']);
      }

      if (!$contactId) {
        // Not particularly useful, but devs can insert a backtrace here if they want to debug the cause.
        // Example: for context = singleEmail, we end up here. We should probably fix core.
        Civi::log()->warning('ContactId not known to attach header for this transactional email by Sparkpost extension possible duplicates email hence skipping: ' . CRM_Utils_Array::value('toEmail', $params));
        return;
      }

      if ($contactId) {
        $eventQueue = CRM_Mailing_Event_BAO_Queue::create([
          'job_id' => CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_MailingJob', $mail->id, 'id', 'mailing_id'),
          'contact_id' => $contactId,
          'email_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $contactId, 'id', 'contact_id'),
        ]);

        // Add m to differentiate from bulk mailing
        $emailDomain = CRM_Core_BAO_MailSettings::defaultDomain();
        $verpSeparator = CRM_Core_Config::singleton()->verpSeparator;
        $params['returnPath'] = implode($verpSeparator, ['m', $eventQueue->job_id, $eventQueue->id, $eventQueue->hash]) . "@$emailDomain";
      }
    }
    else {
      Civi::log()->debug('Sparkpost: the mailing for transactional emails was not found. Bounces will not be tracked. Disable/enable the sparkpost extension to re-create the mailing.');
    }
  }
}

/**
 * Implementation of hook_civicrm_postEmailSend
 */
function sparkpost_civicrm_postEmailSend(&$params) {
  if (!empty($params['returnPath'])) {
    $header = explode(CRM_Core_Config::singleton()->verpSeparator, $params['returnPath']);

    // Do not use $params, it would cause problems to other hooks
    $p = array(
      'job_id' => $header[1],
      'event_queue_id' => $header[2],
      'hash' => $header[3],
    );
    CRM_Mailing_Event_BAO_Delivered::create($p);
  }
}

/**
 * Returns the contact_id for a specific email address.
 * Returns NULL if no contact was found, or if more than one
 * contact was matched.
 *
 * @return contact Id | NULL
 */
function sparkpost_targetContactId($email) {
  // @todo Does this exclude deleted contacts?
  $result = civicrm_api3('email', 'get', [
    'email' => trim($email),
    'sequential' => 1,
    'options' => ['sort' => 'contact_id ASC'],
  ]);

  if ($result['count'] == 1) {
    return $result['values'][0]['contact_id'];
  }
  // contact with several times the same email (e.g. primary + billing)
  elseif ($result['count'] > 1) {
    $contactId = $result['values'][0]['contact_id'];
    foreach ($result['values'] as $row) {
      if ($row['contact_id'] != $contactId) return NULL;
    }
    // every emails found are on the same contact
    return $contactId;
  }

  return NULL;
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

/**
 * Implements hook_civicrm_alterAPIPermissions().
 */
function sparkpost_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  $permissions['sparkpost']['event'] = array('view all contacts');
}

/**
 * Implements hook_civicrm_pageRun().
 */
function sparkpost_civicrm_pageRun(&$page) {
  $pageName = get_class($page);

  if ($pageName == 'CRM_Contact_Page_View_Summary') {
    CRM_Sparkpost_Contact_Page_View_Summary::pageRun($page);
  }
}
