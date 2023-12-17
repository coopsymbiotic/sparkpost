<?php

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

class CRM_Sparkpost_Utils_Check_SendingDomains {

  /**
   * Displays the available sending domains.
   */
  public static function check(&$messages) {
    // TODO: Refactor into a more generic function?
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');

    if (!$api_key) {
      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_sendingdomains',
        ts('The Sparkpost API Key is missing'),
        ts('SparkPost - API Key'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-envelope'
      );
      return;
    }

    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    try {
      $response = $sparky->request('GET', 'sending-domains', [
        'ownership_verified' => TRUE,
      ]);

      $body = $response->getBody();
      $domains = [];

      foreach ($body['results'] as $key => $val) {
        $statuses = [];
        foreach ($val['status'] as $kk => $vv) {
          $statuses[] = "$kk=$vv";
        }

        $domains[] = '<p>' . $val['domain'] . ': ' . implode(', ', $statuses) . '</p>';
      }

      $output = implode('', $domains);

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_sendingdomains',
        ts('Available sending domains: %1', [1 => $output]), // FIXME E::ts
        ts('SparkPost - Sending Domains'),
        \Psr\Log\LogLevel::INFO,
        'fa-envelope'
      );
    }
    catch (Exception $e) {
      $code = $e->getCode();
      $body = $e->getMessage();

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_sendingdomains',
        ts('Available sending domains: ERROR: %1, %2', [1 => $code, 2 => print_r($body, 1)]),
        ts('SparkPost - Sending Domains'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-envelope'
      );
    }
  }

}
