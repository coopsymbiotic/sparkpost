<?php

use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost_Page_Events extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('Email Delivery Events'));

    $email_id = CRM_Utils_Request::retrieveValue('email_id', 'Positive');

    if (!$email_id) {
      throw new Exception("Missing email_id");
    }

    $email = civicrm_api3('Email', 'getsingle', [
      'id' => $email_id,
    ])['email'];

    // Fetch Sparkpost Events
    $events = civicrm_api3('Sparkpost', 'event', [
      'email' => $email,
    ]);

    $this->assign('sparkpost_events', $events['values']);

    // Fetch CiviCRM bounce events
    $errors = [];

    $dao = CRM_Core_DAO::executeQuery('SELECT *
      FROM civicrm_mailing_event_bounce eb
      LEFT JOIN civicrm_mailing_event_queue eq ON (eq.id = eb.event_queue_id)
      WHERE email_id = %1', [
      1 => [$email_id, 'Positive'],
    ]);

    while ($dao->fetch()) {
      $dao2 = CRM_Core_DAO::executeQuery('SELECT j.*, m.subject, m.from_email
        FROM civicrm_mailing_job j
        LEFT JOIN civicrm_mailing m on (m.id = j.mailing_id)
        WHERE j.id = %1', [
        1 => [$dao->job_id, 'Positive'],
      ]);

      if ($dao2->fetch()) {
        $errors[] = [
          'bounce_type_id' => $dao->bounce_type_id,
          'bounce_reason' => $dao->bounce_reason,
          'time_stamp' => $dao->time_stamp,
          'from' => $dao2->from_email,
          'subject' => $dao2->subject,
        ];
      }
    }

    $this->assign('civimail_errors', $errors);

    parent::run();
  }

}
