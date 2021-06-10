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

    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_environment',
      E::ts('The PHP curl library is installed.'),
      E::ts('SparkPost - Environment'),
      \Psr\Log\LogLevel::INFO,
      'fa-envelope'
    );
  }

}
