<?php

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

/**
 * Sparkpost.event API
 *
 * Fetches events in the past 30 days.
 * To filter by recipient email, add a 'email=jane@example.org' param to the API call.
 * To re-process bounce events, add the 'process=1' param.
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_sparkpost_event($params) {
  $result = [
    'values' => [],
  ];

  $from = new DateTime();
  $from = $from->modify('-30 day');
  $from = $from->format('Y-m-d') . 'T00:00:01Z';

  $sp_params = [];
  $sp_params['from'] = $from;
  $sp_params['per_page'] = 10000;
  $sp_params['cursor'] = 'initial';

  if (!empty($params['email'])) {
    $sp_params['recipients'] = $params['email'];
  }

  do {
    $events = CRM_Sparkpost::request('GET', 'events/message', $sp_params);
    $continue = count($events) == 10000;

    foreach ($events as $key => $val) {
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
        'rcpt_to' => $val['rcpt_to'],
        'type' => $val['type'],
        'subject' => $val['subject'],
        'timestamp' => date('Y-m-d H:i'),
        'raw_reason' => $val['raw_reason'],
      ];

      if (!empty($params['process'])) {
        CRM_Sparkpost::processSparkpostEvent($val);
      }
    }
    sleep(1);
  } while ($continue);

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

/**
 * List Sparkpost webhooks. Useful for debugging.
 */
function civicrm_api3_sparkpost_list_webhooks($params) {
  $results = CRM_Sparkpost::request('GET', 'webhooks', $params);
  return $results;
}

/**
 * Create a new Sparkpost webhook (or update, if the name/target matches,
 * useful for re-enabling an inactive webhook)
 */
function civicrm_api3_sparkpost_create_webhook($params) {
  // @todo Fix spec
  if (empty($params['name'])) {
    throw new Exception("Required param: name");
  }
  if (empty($params['target'])) {
    throw new Exception("Required param: target");
  }

  $method = 'POST';
  $uri = 'webhooks';

  // Check if it already exists, but needs updating
  $results = CRM_Sparkpost::request('GET', 'webhooks');

  foreach ($results as $webhook) {
    if ($webhook['name'] == $params['name'] && $webhook['target'] == $params['target']) {
      $method = 'PUT';
      $uri = 'webhooks/' . $webhook['id'];
    }
  }

  $results = CRM_Sparkpost::request($method, $uri, [
    'name' => $params['name'],
    'target' => $params['target'],
    'events' => [
      'policy_rejection',
      'bounce',
      'out_of_band',
      'spam_complaint',
      'link_unsubscribe',
      'relay_rejection',
      'relay_permfail',
      'error',
    ],
    'active' => 1,
  ]);

  return $results;
}
