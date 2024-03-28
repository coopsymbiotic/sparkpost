<?php

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost_Utils_Check_Metrics {

  /**
   * Displays the available sending domains.
   */
  public static function check(&$messages) {
    // TODO: Refactor into a more generic function?
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');
    $sending_quota = Civi::settings()->get('sparkpost_sending_quota');

    if (!$api_key) {
      // The SendingDomains check is already displaying an error
      return;
    }

    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    // FIXME Add cycle roll day setting
    // If it's before the 27th, then we search Y-[m-1]-27
    // otherwise we check Y-m-27.
    $date = new DateTime();
    $date->setDate($date->format('Y'), $date->format('m'), 27);

    $current_day = date('d');

    if ($current_day <= 27) {
      $date->setDate($date->format('Y'), $date->format('m') - 1, 27);
    }

    // Metrics by Sending Domain
    try {
      $response = $sparky->request('GET', 'metrics/deliverability/sending-domain', [
        'from' => $date->format('Y-m-d') . 'T00:01',
        'metrics' => 'count_sent,count_bounce,count_rejected,count_admin_bounce,count_rejected,count_spam_complaint',
        // TODO This is mostly relevant for the master account, which sees all domains
        // For now, we find it convenient to see stats for all domains.
        // 'limit' => 20,
      ]);

      $body = $response->getBody();

      $output = '';

      // https://www.sparkpost.com/docs/reporting/metrics-definitions/
      $metrics = [
        'count_sent' => [
          'quota_total' => $sending_quota,
          'label' => E::ts('Messages Sent:'),
        ],
        'count_bounce' => [
          'quota_pct' => 10,
          'label' => E::ts('Bounces:'),
        ],
        'count_admin_bounce' => [
          'quota_pct' => Civi::settings()->get('sparkpost_bounce_rate'),
          'label' => E::ts('Bounced by Sparkpost:'),
        ],
        'count_rejected' => [
          'quota_pct' => 5,
          'label' => E::ts('Rejected by Sparkpost:'),
        ],
        'count_spam_complaint' => [
          'quota_pct' => 5,
          'label' => E::ts('Spam Complaints:'),
        ],
      ];

      $log_level = \Psr\Log\LogLevel::INFO;

      foreach ($body['results'] as $key => $val) {
        $stats = [];

        foreach ($metrics as $mkey => $mval) {
          if (!empty($mval['quota_total']) && $val[$mkey] > $mval['quota_total']) {
            $pct = round($val[$mkey] / $mval['quota_total'] * 100, 2);
            // FIXME ts
            $stats[] = "<li><strong>{$mval['label']} {$val[$mkey]} (max: {$mval['quota_total']}, $pct %)</strong></li>";
            $log_level = \Psr\Log\LogLevel::CRITICAL;
            if ($key == 'count_sent') {
              $alert = Civi::settings()->get('sparkpost_sending_quota_alert');
              if ($alert && $val[$mkey] < $alert) {
                $log_level = \Psr\Log\LogLevel::WARNING;
              }
            }
          }
          elseif (!empty($mval['quota_pct']) && !empty($val['count_sent'])) {
            $pct_val = round($val[$mkey] / $val['count_sent'] * 100, 2);

            // For most metrics, under 50 complaints/bounces, there is too much of a high noize ratio.
            if ($val[$mkey] > 50 && $pct_val > $mval['quota_pct']) {
              $stats[] = "<li><strong>{$mval['label']} {$val[$mkey]} ({$pct_val}%)</strong></li>";
              $log_level = \Psr\Log\LogLevel::CRITICAL;
            }
            else {
              $stats[] = "<li>{$mval['label']} {$val[$mkey]} ({$pct_val}%)</li>";
            }
          }
          else {
            $stats[] = "<li>" . $mval['label'] . ' ' . $val[$mkey] . '</li>';
          }
        }

        $output .= '<p>' . E::ts('Domain:') . ' ' . $val['sending_domain'] . '</p><ul>' . implode('', $stats) . '</ul>';
      }

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_metrics',
        E::ts('Emails sent from %1 to today: %2', [1 => $date->format('Y-m-d'), 2 => $output]),
        E::ts('SparkPost - Metrics'),
        $log_level,
        'fa-envelope'
      );
    }
    catch (Exception $e) {
      $code = $e->getCode();
      $body = $e->getMessage();

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_metrics',
        E::ts('Metrics: ERROR: %1, %2', [1 => $code, 2 => print_r($body, 1)]),
        E::ts('SparkPost - Metrics'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-envelope'
      );
    }
  }

}
