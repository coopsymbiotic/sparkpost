<?php

require_once 'sparkpost.civix.php';
use CRM_Sparkpost_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function sparkpost_civicrm_config(&$config) {
  _sparkpost_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 */
function sparkpost_civicrm_xmlMenu(&$files) {
  _sparkpost_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 */
function sparkpost_civicrm_install() {
  _sparkpost_civix_civicrm_install();
  CRM_Sparkpost::createTransactionalMailing();
}

/**
 * Implements hook_civicrm_postInstall().
 */
function sparkpost_civicrm_postInstall() {
  _sparkpost_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 */
function sparkpost_civicrm_uninstall() {
  _sparkpost_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 */
function sparkpost_civicrm_enable() {
  _sparkpost_civix_civicrm_enable();
  CRM_Sparkpost::createTransactionalMailing();
}

/**
 * Implements hook_civicrm_disable().
 */
function sparkpost_civicrm_disable() {
  return _sparkpost_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 */
function sparkpost_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sparkpost_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function sparkpost_civicrm_managed(&$entities) {
  _sparkpost_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function sparkpost_civicrm_navigationMenu(&$menu) {
  _sparkpost_civix_insert_navigation_menu($menu, 'Administer/CiviMail', [
    'label' => E::ts('Sparkpost'),
    'name' => 'sparkpost_settings',
    'url' => 'civicrm/admin/setting/sparkpost',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _sparkpost_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_alterMailer().
 */
function sparkpost_civicrm_alterMailer(&$mailer, $driver, $params) {
  // Do not process emails "logged to DB", for example
  if (in_array($driver, ['smtp', 'sendmail', 'mail'])) {
    require_once 'Mail/sparkpost.php';
    $sparkpost = new Mail_sparkpost($params);
    $sparkpost->setBackupMailer($mailer);
    $mailer = $sparkpost;
  }
}

/**
 * Implements hook_civicrm_check().
 */
function sparkpost_civicrm_check(&$messages) {
  CRM_Sparkpost_Utils_Check_SendingDomains::check($messages);
  CRM_Sparkpost_Utils_Check_Metrics::check($messages);
  CRM_Sparkpost_Utils_Check_Environment::check($messages);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
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
        // try to get email from the toEmail
        $email_id = NULL;
        try {
          $email_id = civicrm_api3('Email', 'getvalue', [
            'return' => "id",
            'contact_id' => $contactId,
            'email' => $params['toEmail'],
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          // fail silently
        }

        // fallback - get primary email but there is a risk of disabling the wrong email in case of bouncing
        if (empty($email_id)) {
          $email_id = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $contactId, 'id', 'contact_id');
        }

        $eventQueue = CRM_Mailing_Event_BAO_Queue::create([
          'job_id' => CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_MailingJob', $mail->id, 'id', 'mailing_id'),
          'contact_id' => $contactId,
          'email_id' => $email_id,
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
 * Implements hook_civicrm_postEmailSend().
 */
function sparkpost_civicrm_postEmailSend(&$params) {
  if (!empty($params['returnPath'])) {
    $header = explode(CRM_Core_Config::singleton()->verpSeparator, $params['returnPath']);

    // recordDelivery uses pass-by-ref for some reason
    $p = [
      'job_id' => $header[1],
      'event_queue_id' => $header[2],
      'hash' => $header[3],
    ];
    CRM_Mailing_Event_BAO_Delivered::recordDelivery($p);
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
      if ($row['contact_id'] != $contactId) {
        return NULL;
      }
    }
    // every emails found are on the same contact
    return $contactId;
  }

  return NULL;
}

/**
 * Implements hook_civicrm_pre().
 */
function sparkpost_civicrm_pre($op, $objectName, $objectId, &$objectRef) {
  // When email is updated for contact, check on hold flag is changed?
  // If changed (unchecked) then remove same email from sparkpost suppression list
  global $resetHoldFlag;
  $resetHoldFlag = FALSE;

  if ($objectName == 'Email' && $op == 'edit' && $objectId && empty($objectRef['on_hold'])) {
    $resultEmail = civicrm_api3('Email', 'getsingle', [
      'id' => $objectId,
    ]);
    if ($resultEmail['on_hold'] == 1) {
      $resetHoldFlag = TRUE;
    }
  }
}

/**
 * Implements hook_civicrm_post().
 */
function sparkpost_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  // Previous on hold flag = 1 and Current on hold flag = 0 then remove from sparkpost suppression list
  global $resetHoldFlag;

  if ($objectName == 'Email' && $op == 'edit' && $resetHoldFlag && !empty($objectRef->email)) {
    try {
      $result = CRM_Sparkpost::call('suppression-list/' . $objectRef->email);
    }
    catch (Exception $e) {
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

/**
 * Implements hook_civicrm_buildForm().
 */
function sparkpost_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Admin_Form_Setting_Smtp') {
    CRM_Core_Region::instance('smtp-mailer-config')->add([
      'template' => 'CRM/Sparkpost/Smtp-extra.tpl',
      'weight' => -10,
    ]);
  }
}
