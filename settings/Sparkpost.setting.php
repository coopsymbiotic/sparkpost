<?php
/**
 * Settings metadata file.
 */

require_once(__DIR__ . "/../CRM/Sparkpost.php");

return array(
  'sparkpost_host' => array(
    'group_name' => CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_host',
    'type' => 'String',
    'quick_form_type' => 'Select',
    'html_type' => 'Select',
    'html_attributes' => array(),
    'default' => 'sparkpost.com',
    'add' => '4.7',
    'title' => 'Sparkpost Host',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => array(
      'callback' => 'CRM_Sparkpost::getSparkpostHostOptions',
    ),
    'description' => 'Enable to use the EU-hosted platform. You will need an account with the <a href="https://app.eu.sparkpost.com">EU-hosted platform</a>.',
    'help_text' => 'European accounts have to use *.eu.sparkpost.com',
  ),
  'sparkpost_apiKey' => array(
    'group_name' => 'SparkPost Extension Settings',
    'group' => 'com.cividesk.email.sparkpost',
    'name' => 'sparkpost_apiKey',
    'type' => 'String',
    'html_type' => 'password',
    'default' => null,
    'add' => '4.4',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'SparkPost REST API key',
    'help_text' => 'You can create API keys at: https://app.sparkpost.com/account/api-keys, or https://app.eu.sparkpost.com/account/api-keys if using the EU-hosted platform.',
  ),
  'sparkpost_customCallbackUrl' => array(
    'group_name' => 'SparkPost Extension Settings',
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
    'group_name' => 'SparkPost Extension Settings',
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
