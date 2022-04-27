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
    'description' => 'Select the host. If you are using an EU-hosted platform you will need an <a href="https://app.eu.sparkpost.com">European account</a>.',
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
  'sparkpost_sending_quota' => [
    'name' => 'sparkpost_sending_quota',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 65000,
    'add' => '1.7',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Monthly sending quota. This is checked against Sparkpost metrics. Above this number, a critical Status Check will be displayed, but it will not stop sending emails. It can be useful for monitoring.',
  ],
);
