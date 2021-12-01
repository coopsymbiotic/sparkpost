<?php

use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost_Utils_Check_Environment {

  /**
   * Checks for operating system and other environment requirements.
   */
  public static function check(&$messages) {
    if (!function_exists('curl_version')) {
      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_environment_curl',
        E::ts('The PHP curl library is missing.'),
        E::ts('SparkPost - Environment'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-envelope'
      );

      return;
    }

    // Check if the special mailing for transactional emails is present
    $result = civicrm_api3('Mailing', 'get', [
      'name' => 'Sparkpost Transactional Emails',
    ]);

    if (empty($result['values'])) {
      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_environment_tracking',
        E::ts('The special Mailing for tracking transactional emails is missing. Disable/Enable the Sparkpost extension to re-create it.'),
        E::ts('SparkPost - Environment'),
        \Psr\Log\LogLevel::WARNING,
        'fa-envelope'
      );

    }

    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_environment',
      E::ts('The PHP curl library is installed.'),
      E::ts('SparkPost - Environment'),
      \Psr\Log\LogLevel::INFO,
      'fa-envelope'
    );
  }

}
