<?php

use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Admin_Form_Setting_Sparkpost extends CRM_Admin_Form_Setting {
  protected $_testButtonName;

  /**
   * Build the form object.
   *
   * @return void
   */
  public function buildQuickForm() {
    $this->add('password', 'sparkpost_apiKey', E::ts('API Key'), '', TRUE);
    $this->add('text', 'sparkpost_ipPool', E::ts('IP pool'));
    $this->addYesNo('sparkpost_useBackupMailer', E::ts('Use backup mailer'));
    $host_options = CRM_Sparkpost::getSparkpostHostOptions();
    $this->add('select', 'sparkpost_host', E::ts('Sparkpost host'), $host_options);
    $this->add('text', 'sparkpost_customCallbackUrl', E::ts('Custom callback URL'));
    $this->add('text', 'sparkpost_sending_quota', E::ts('Monthly Quota'));
    $this->add('text', 'sparkpost_sending_quota_alert', E::ts('Monthly Quota Alert'));
    $this->add('text', 'sparkpost_bounce_rate', E::ts('Bounce Rate'));

    $this->_testButtonName = $this->getButtonName('refresh', 'test');

    $this->addFormRule(array('CRM_Admin_Form_Setting_Sparkpost', 'formRule'));
    parent::buildQuickForm();
    $buttons = $this->getElement('buttons')->getElements();
    $buttons[] = $this->addElement('xbutton', $this->_testButtonName, E::ts('Save and Send Test Email'), ['crm-icon' => 'mail-closed']);
    $this->getElement('buttons')->setElements($buttons);

    // Get the logged in user's email address
    $session = CRM_Core_Session::singleton();
    $userID = $session->get('userID');
    list($toDisplayName, $toEmail, $toDoNotEmail) = CRM_Contact_BAO_Contact::getContactDetails($userID);

    $this->assign('sparkpost_test_email', $toEmail);
  }

  /**
   * Set default values for the form.
   *
   * @return array
   *   default value for the fields on the form
   */
  public function setDefaultValues() {
    $this->_defaults = CRM_Sparkpost::getSetting();
    return $this->_defaults;
  }

  /**
   * Global validation rules for the form.
   *
   * @param array $fields
   *   Posted values of the form.
   *
   * @return array
   *   list of errors to be posted back to the form
   */
  public static function formRule($fields) {
    if (empty($fields['sparkpost_apiKey'])) {
      $errors['sparkpost_apiKey'] = 'You must enter an API key.';
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Process the form submission.
   *
   * @return void
   */
  public function postProcess() {
    // CRM-11967 Flush caches so we reload details if memcache is active
    CRM_Utils_System::flushCache();

    $formValues = $this->controller->exportValues($this->_name);
    foreach (array('sparkpost_apiKey', 'sparkpost_ipPool', 'sparkpost_useBackupMailer', 'sparkpost_customCallbackUrl', 'sparkpost_host', 'sparkpost_sending_quota', 'sparkpost_sending_quota_alert', 'sparkpost_bounce_rate') as $name) {
      CRM_Sparkpost::setSetting($name, $formValues[$name]);
    }

    $buttonName = $this->controller->getButtonName();

    // Check if test button
    if ($buttonName == $this->_testButtonName) {
      $session = CRM_Core_Session::singleton();

      // Get the logged in user's email address
      $userID = $session->get('userID');
      list($toDisplayName, $toEmail, $toDoNotEmail) = CRM_Contact_BAO_Contact::getContactDetails($userID);
      if (!$toEmail) {
        CRM_Core_Error::statusBounce(E::ts('Cannot send a test email because your user record does not have a valid email address.'));
      }

      // CRM-4250: Get the default domain email address
      list($domainEmailName, $domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail();
      if (!$domainEmailAddress || $domainEmailAddress == 'info@EXAMPLE.ORG') {
        $fixUrl = CRM_Utils_System::url("civicrm/admin/domain", 'action=update&reset=1');
        CRM_Core_Error::fatal(E::ts('The site administrator needs to enter a valid \'FROM Email Address\' in <a href="%1">Administer CiviCRM &raquo; Communications &raquo; FROM Email Addresses</a>. The email address used may need to be a valid mail account with your email service provider.', [1 => $fixUrl]));
      }

      // Test that the sending domain is correctly configured
      $domain = substr(strrchr($domainEmailAddress, "@"), 1);
      $sparkpost_host = CRM_Sparkpost::getSetting('sparkpost_host');

      try {
        $response = CRM_Sparkpost::call("sending-domains/$domain");
      }
      catch (Exception $e) {
        if (strpos($e->getMessage(), 'Invalid request') !== FALSE) {
          $url = "https://app.$sparkpost_host/account/sending-domains";
          CRM_Core_Session::setStatus(E::ts('Domain %1 is not created and not verified in Sparkpost. Please follow instructions at <a href="%2">%2</a>.', [1 => $domain, 2 => $url]), ts('SparkPost error'), 'error');
          return;
        }
        else {
          CRM_Core_Session::setStatus(E::ts('Could not check status for domain %1 (Exception %2).', [1 => $domain, 2 => $e->getMessage()]), E::ts('SparkPost error'), 'error');
          return;
        }
      }

      if (!$response->results || !$response->results->status || !$response->results->status->ownership_verified) {
        $url = "https://app.$sparkpost_host/account/sending-domains";
        CRM_Core_Session::setStatus(E::ts('The domain \'%1\' is not verified. Please follow instructions at <a href="%2">%2</a>.', [1 => $domain, 2 => $url]), E::ts('SparkPost error'), 'errors');
        return;
      }
      else {
        CRM_Core_Session::setStatus(E::ts('The domain %1 is ready to send.', array(1 => $domain)), E::ts('SparkPost status'), 'info');
      }

      $campaign = CRM_Sparkpost::getSetting('sparkpost_campaign');
      if (empty($campaign)) {
        // Get the id of (potentially) existing webhook
        try {
          $response = CRM_Sparkpost::call("webhooks");
        }
        catch (Exception $e) {
          CRM_Core_Session::setStatus(E::ts('Could not list webhooks (%1).', [1 => $e->getMessage()]), E::ts('SparkPost error'), 'error');
          return;
        }
        // Define parameters for our webhook
        $my_webhook = [
          'name' => 'CiviCRM (sparkpost-symbiotic)',
          'target' => CRM_Sparkpost::getSetting('sparkpost_customCallbackUrl') ?
            CRM_Sparkpost::getSetting('sparkpost_customCallbackUrl') :
            CRM_Utils_System::url('civicrm/sparkpost/callback', NULL, TRUE, NULL, FALSE, TRUE),
          'auth_type' => 'none',
          // Just bounce-related events as click and open tracking are still done by CiviCRM
          'events' => ['bounce', 'spam_complaint', 'policy_rejection'],
        ];
        // Has this webhook already been created?
        $webhook_id = FALSE;
        foreach ($response->results as $webhook) {
          if ($webhook->name == $my_webhook['name']) {
            $webhook_id = $webhook->id;
          }
        }
        // Install our webhook (or refresh it if already there)
        try {
          $response = CRM_Sparkpost::call('webhooks' . ($webhook_id ? "/$webhook_id" : ''), array(), $my_webhook);
        }
        catch (Exception $e) {
          CRM_Core_Session::setStatus(E::ts('Could not install webhook (%1).', [1 => $e->getMessage()]), E::ts('SparkPost error'), 'error');
          return;
        }
        if (!$response->results || !$response->results->id) {
          CRM_Core_Session::setStatus(E::ts('Could not install/refresh webhook.'), E::ts('SparkPost error'), 'error');
          return;
        }
        else {
          CRM_Core_Session::setStatus(E::ts('Webhook has been installed or refreshed.'), E::ts('SparkPost status'), 'info');
        }
      }

      if (!trim($toDisplayName)) {
        $toDisplayName = $toEmail;
      }
      $to = '"' . $toDisplayName . '"' . "<$toEmail>";
      $from = '"' . $domainEmailName . '" <' . $domainEmailAddress . '>';
      $testMailStatusMsg = E::ts('Sending test email. FROM: %1 TO: %2.', [
        1 => $domainEmailAddress,
        2 => $toEmail,
      ]) . '<br/>';

      $subject = E::ts('Test for SparkPost settings');
      $message = E::ts('Your SparkPost settings are correct');
      $headers = [
        'From' => $from,
        'To' => $to,
        'Subject' => $subject,
      ];
      $mailer = Mail::factory('Sparkpost', []);

      $errorScope = CRM_Core_TemporaryErrorScope::ignoreException();
      $result = $mailer->send($toEmail, $headers, $message);
      unset($errorScope);

      if (!is_a($result, 'PEAR_Error')) {
        CRM_Core_Session::setStatus($testMailStatusMsg . E::ts('Your %1 settings are correct. A test email has been sent to your email address.', [1 => 'SparkPost']), E::ts("Mail Sent"), 'success');
      }
      else {
        $message = CRM_Utils_Mail::errorMessage($mailer, $result);
        CRM_Core_Session::setStatus($testMailStatusMsg . E::ts('Oops. Your %1 settings are incorrect. No test mail has been sent.', [1 => 'SparkPost']) . $message, E::ts("Mail Not Sent"), 'error');
      }
    }
  }

}
