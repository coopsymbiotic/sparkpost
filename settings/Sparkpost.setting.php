<?php
/**
 * Settings metadata file.
 */

require_once(__DIR__ . "/../CRM/Sparkpost.php");

return array(
  'sparkpost_EUhost' => array(
    'group_name' => CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_EUhost',
    'type' => 'Boolean',
    'html_type' => 'radio',
    'default' => FALSE,
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'EU-hosted platform',
    'help_text' => 'European accounts have to use *.eu.sparkpost.com',
  ),
  'sparkpost_apiKey' => array(
    'group_name' => CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_apiKey',
    'type' => 'String',
    'html_type' => 'password',
    'default' => null,
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'SparkPost REST API key',
    'help_text' => 'You can create API keys at: https://app.sparkpost.com/account/api-keys',
  ),
  'sparkpost_customCallbackUrl' => array(
    'group_name' => CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_customCallbackUrl',
    'type' => 'String',
    'html_type' => 'text',
    'default' => null,
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'A custom callback URL. Useful if your site is behind a proxy (like CiviProxy)',
    'help_text' => 'A custom callback URL is useful when your site is behind a proxy (like CiviProxy)',
  ),
  'sparkpost_useBackupMailer' => array(
    'group_name' => CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_useBackupMailer',
    'type' => 'Boolean',
    'html_type' => 'radio',
    'default' => FALSE,
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Use backup mailer?',
    'help_text' => 'The backup mailer will be used if Sparkpost cannot send emails (unverified sending domain, sending limits exceeded, ...).',
  ),
);
