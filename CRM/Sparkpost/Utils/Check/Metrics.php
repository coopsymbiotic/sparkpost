<?php

use SparkPost\SparkPost;
use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost_Utils_Check_Metrics {

  /**
   * Displays the available sending domains.
   */
  public static function check(&$messages) {
    // TODO: Refactor into a more generic function?
    if (file_exists(__DIR__ . '/../../../../vendor/autoload.php')) {
      require_once __DIR__ . '/../../../../vendor/autoload.php';
    }
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');
    $sending_quota = Civi::settings()->get('sparkpost_sending_quota');

    if (!$api_key) {
      // The SendingDomains check is already displaying an error
      return;
    }

    $httpClient = new \GuzzleHttp\Client();
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    // Fetch stats for the past 30 days
    $date->modify('-30 days');

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

      // CiviMail: past 30 days
      $output .= '<p>CiviMail Stats:</p>';
      $output .= '<ul>';

      $sent = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_event_delivered WHERE time_stamp > NOW() - INTERVAL 30 DAY');
      if ($sent > $sending_quota) {
        $log_level = \Psr\Log\LogLevel::CRITICAL;
        $output .= '<li><strong>' . E::ts('CiviMail Sent - past 30 days: %1', [1 => Civi::format()->number($sent)]) . '</strong></li>';
      }
      else {
        $output .= '<li>' . E::ts('CiviMail Sent - past 30 days: %1', [1 => Civi::format()->number($sent)]) . '</li>';
      }

      // CiviMail: this month
      $sent = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_event_delivered WHERE YEAR(time_stamp) = YEAR(CURDATE()) AND MONTH(time_stamp) = MONTH(CURDATE())');
      if ($sent_past_30_days > $sending_quota) {
        $log_level = \Psr\Log\LogLevel::CRITICAL;
        $output .= '<li><strong>' . E::ts('CiviMail Sent - this calendar month: %1', [1 => Civi::format()->number($sent)]) . '</strong></li>';
      }
      else {
        $output .= '<li>' . E::ts('CiviMail Sent - this calendar month: %1', [1 => Civi::format()->number($sent)]) . '</li>';
      }

      $output .= '<li>' . E::ts('Monthly Quota: %1', [1 => Civi::format()->number($sending_quota)]) . '</li>';
      $output .= '</ul>';

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
