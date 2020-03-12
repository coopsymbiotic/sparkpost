<?php

use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost_Page_Events extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(E::ts('Sparkpost Email Delivery Events'));

    $email_id = CRM_Utils_Request::retrieveValue('email_id', 'Positive');

    if ($email_id) {
      $email = civicrm_api3('Email', 'getsingle', [
        'id' => $email_id,
      ])['email'];

      $events = civicrm_api3('Sparkpost', 'event', [
        'email' => $email,
      ]);

      $this->assign('sparkpost_events', $events['values']);
    }

    parent::run();
  }

}
