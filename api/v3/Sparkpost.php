<?php

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

/**
 * Sparkpost.event API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_sparkpost_event($params) {
  $result = [
    'values' => [],
  ];

  $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
  $api_host = Civi::settings()->get('sparkpost_host');

  // FIXME: We should use Guzzle from core, and autoload from sparkpost.php?
  require_once __DIR__ . '/../../vendor/autoload.php';

  $httpClient = new GuzzleAdapter(new Client());
  $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

  $from = new DateTime();
  $from = $from->modify('-30 day');
  $from = $from->format('Y-m-d') . 'T00:00:01Z';

  $response = $sparky->request('GET', 'events/message', [
    'recipients' => $params['email'],
    'from' => $from,
  ]);

  // $response = $promise->wait();
  $body = $response->getBody();

  foreach ($body['results'] as $key => $val) {
    // We are ignoring because 99% of the time it's just noize
    // because injection is followed by a delivery or rejection.
    if ($val['type'] == 'injection') {
      continue;
    }

    $date = strftime($val['timestamp']);

    // Intentionally keeping this short in order to reduce debug noize.
    $result['values'][] = [
      'event_id' => $val['event_id'],
      'friendly_from' => $val['friendly_from'],
      'type' => $val['type'],
      'subject' => $val['subject'],
      'timestamp' => date('Y-m-d H:i'),
      'raw_reason' => $val['raw_reason'],
    ];
  }

  return $result;
}

/**
 * Sparkpost.create_transactional_mailing API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_sparkpost_create_transactional_mailing($params) {
  $result = [
    'values' => [],
  ];

  CRM_Sparkpost::createTransactionalMailing();

  return $result;
}
