<?php
/**
 * This extension allows CiviCRM to send emails and process bounces through
 * the SparkPost service.
 *
 * Copyright (c) 2016 IT Bliss, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Support: https://github.com/cividesk/com.cividesk.email.sparkpost/issues
 * Contact: info@cividesk.com
 */

/**
 * Outbound mailer class which calls the SparkPost APIs (SMTP with TLS does not work)
 * @see packages/Mail/smtp.php
 */

require_once 'Mail/RFC822.php';

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use CRM_Sparkpost_ExtensionUtil as E;

class Mail_sparkpost extends Mail {
  protected $backupMailer;

  /**
   * Sets a backup mailer
   */
  public function setBackupMailer($mailer) {
    $this->backupMailer = $mailer;
  }

  /**
   * Send an email
   *
   * The calling function in CRM_Utils_Mail::send() handles exceptions
   * or if we return a PEAR_Error. This function either throws an Exception
   * or returns TRUE.
   */
  public function send($recipients, $headers, $body) {
    // Special bypass switch that alterMailParams can use to disable sparkpost
    // we use this only for a very specific use-case, where for contact forms
    // we send directly via SMTP so that we can change the "From" (Gitlab
    // service desk)
    if (!empty(Civi::$statics['sparkpost_bypass'])) {
      return $this->backupMailer->send($recipients, $headers, $body);
    }

    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');

    if (empty($api_key)) {
      throw new Exception('No API key defined for SparkPost');
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    // Verify if the sending domain is valid
    if (!empty($headers['From'])) {
      if (!CRM_Sparkpost::isValidSparkpostVerifiedSendingEmail($headers['From'])) {
        throw new Exception(E::ts('The email could not be sent because the sender (%1) email is not a verified domain.', [1 => htmlspecialchars($headers['From'])]));
      }
    }

    // Prepare to send the email
    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    // Sanitize and prepare headers for transmission
    if (!is_array($headers)) {
      return PEAR::raiseError('$headers must be an array');
    }

    $this->_sanitizeHeaders($headers);
    $headerElements = $this->prepareHeaders($headers);

    if (is_a($headerElements, 'PEAR_Error')) {
      return $headerElements;
    }

    list($from, $textHeaders) = $headerElements;

    // NB: we disable any type of SparkPost tracking, because a few sources may
    // flag the links as spam. CiviCRM also does tracking anyway.
    $sp = [
      'content' => [],
      'options' => [
        'open_tracking' => FALSE,
        'click_tracking' => FALSE,
      ],
    ];

    // Should we send via a dedicated IP pool?
    $ip_pool = CRM_Sparkpost::getSetting('sparkpost_ipPool');
    if (!empty($ip_pool)) {
      $sp['options']['ip_pool'] = $ip_pool;
    }

    // Is this a CiviMail mailing or a transactional email?
    if (CRM_Utils_Array::value('X-CiviMail-Bounce', $headers)) {
      // Insert CiviMail header in the outgoing email's metadata
      $sp['metadata'] = ['X-CiviMail-Bounce' => CRM_Utils_Array::value("X-CiviMail-Bounce", $headers)];
    }
    else {
      // Mark the email as transactional for SparkPost
      $sp['options']['transactional'] = TRUE;

      // Attach metadata for transactional email
      if (CRM_Utils_Array::value('Return-Path', $headers)) {
        // On Standalone, during login, for some reason the verpSeparator might return empty sometimes
        // which then causes a fatal on the explode(), so we make sure to have some default value
        $verpSeparator = Civi::settings()->get('verpSeparator') ?: '.';
        $metadata = explode($verpSeparator, CRM_Utils_Array::value("Return-Path", $headers));
        if ($metadata[0] == 'm') {
          $localpart = CRM_Sparkpost::getDomainLocalpart();
          $sp['metadata'] = ['X-CiviMail-Bounce' => $localpart . CRM_Utils_Array::value("Return-Path", $headers)];
        }
      }
    }

    // Attach mailing name as campaign_id for sparkpost
    if (!empty($sp['metadata'])) {
      $verpSeparator = Civi::settings()->get('verpSeparator') ?: '.';
      $metadata = explode($verpSeparator, $sp['metadata']['X-CiviMail-Bounce']);
      list($mailing_id, $mailing_name) = self::getMailing($metadata[1]);

      if ($mailing_id) {
        $sp['campaign_id'] = $mailing_id;
      }
    }

    $sp['recipients'] = $this->formatRecipients($recipients);

    if (preg_match('/<style\w+type="text\/css">/', $body)) {
      $body = preg_replace('/<style\w+type="text\/css">/', '<html><head>/<style type="text/css">', $body);
      $body = preg_replace('/<\/style>/', '</head></style>', $body);
    }

    # $sp['inlineCss'] = TRUE;
    $sp['content']['email_rfc822'] = $textHeaders . "\r\n\r\n" . $body;

    try {
      $promise = $sparky->transmissions->post($sp);
      if (defined('CIVICRM_MAIL_LOG_AND_SEND')) {
        CRM_Utils_Mail_Logger::filter($this, $sp['recipients'], $textHeaders, $body);
      }
    }
    catch (Exception $e) {
      $body = $e->getBody();

      // Most of the time we get an array, but sometimes we do not and need to investigate this more
      if (empty($body['errors']) || !is_array($body['errors'])) {
        Civi::log()->error('SPARKPOST transmission error: ' . print_r($sp['recipients'], 1) . ' --- ' . print_r($body, 1));
        throw new Exception(print_r($sp, 1) . ' -- ' . print_r($body, 1) . ' -- ' . $e->getMessage());
      }

      // https://www.sparkpost.com/docs/tech-resources/extended-error-codes/
      foreach ($body['errors'] as $key => $val) {
        // "recipient address suppressed due to customer policy"
        if ($val['code'] == 1902) {
          # $email = $sp['recipients'][0]['address']['email'];
          # $status = $sparky->request('GET', 'suppression-list/' . $email);
          # sparkpost_log(print_r($status->getBody(), 1));
          Civi::log()->warning('SPARKPOST transmission warning [' . $sp['recipients'][0]['address']['email'] . '] suppressed due to customer policy: ' . print_r($e->getBody(), 1));
        }
        elseif ($val['code'] == 5002) {
          // Invalid recipient
          Civi::log()->warning('SPARKPOST invalid recipient was ignored [' . $sp['recipients'][0]['address']['email'] . '] suppressed due to customer policy: ' . print_r($e->getBody(), 1));

          if (!empty($sp['metadata']['X-CiviMail-Bounce'])) {
            $civimail_bounce_id = $sp['metadata']['X-CiviMail-Bounce'];
            $header = CRM_Sparkpost::getPartsFromBounceID($civimail_bounce_id);

            if (empty($header)) {
              Civi::log()->error('Failed to parse the email bounce ID {header}', [
                'header' => $civimail_bounce_id,
              ]);
              return;
            }

            $params = [
              'job_id' => $header['job_id'],
              'event_queue_id' => $header['event_queue_id'],
              'hash' => $header['hash'],
              // 10 = Invalid recipient
              'bounce_type_id' => 10,
              'bounce_reason' => $val['error'],
            ];

            CRM_Mailing_Event_BAO_Bounce::create($params);
          }
        }
        else {
          Civi::log()->error('SPARKPOST transmission error: ' . print_r($sp['recipients'], 1) . ' --- ' . print_r($e->getBody(), 1));
          throw new Exception(print_r($sp, 1) . ' -- ' . print_r($e->getBody(), 1) . ' -- ' . $e->getMessage());
        }
      }
    }

    return TRUE;
  }

  /**
   * Prepares a recipient list in the format SparkPost expects.
   *
   * @param mixed $recipients
   *   List of recipients, either as a string or an array.
   *   @see Mail->send().
   * @return array
   *   An array of recipients in the format that the SparkPost API expects.
   */
  public function formatRecipients($recipients) {
    // CiviCRM passes the recipients as an array of string, each string potentially containing
    // multiple addresses in either abbreviated or full RFC822 format, e.g.
    // $recipients:
    //   [0] nicolas@cividesk.com, Nicolas Ganivet <nicolas@cividesk.com>
    //   [1] "Ganivet, Nicolas" <nicolas@cividesk.com>
    //   [2] ""<nicolas@cividesk.com>,<nicolas@cividesk.com>
    // [0] are the most common cases, [1] note the , inside the quoted name, [2] are edge cases
    // cf. CRM_Utils_Mail::send() lines 161, 171 and 174 (assignments to the $to variable)
    if (!is_array($recipients)) {
      $recipients = [$recipients];
    }
    $result = [];

    foreach ($recipients as $recipientString) {
      // Workaround known bug: empty emails when using Send Email with a CC but no BCC
      $recipientString = preg_replace('/, "" <>/', '', $recipientString);

      // Best is to use the PEAR::Mail package to decapsulate as they have a class just for that!
      $rfc822 = new Mail_RFC822($recipientString);
      $matches = $rfc822->parseAddressList();

      foreach ($matches as $match) {
        $address = [];
        if (!empty($match->mailbox) && !empty($match->host)) {
          $address['email'] = $match->mailbox . '@' . $match->host;
        }
        if (!empty($match->personal)) {
          if ((substr($match->personal, 0, 1) == '"') && (substr($match->personal, -1) == '"')) {
            $address['name'] = substr($match->personal, 1, -1);
          }
          else {
            $address['name'] = $match->personal;
          }
        }
        if (!empty($address['email'])) {
          $result[] = ['address' => $address];
        }
      }
    }

    return $result;
  }
  
  public static function getMailing($jobId) {
    if (!$jobId) {
      return;
    }

    try {
      $mailing_id = CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_MailingJob', $jobId, 'mailing_id');
      $mailing_name = CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_Mailing', $mailing_id, 'name');
    }
    catch (Exception $e) {
      Civi::log()->debug('Sparkpost getMailing failed to getFieldValue for jobId : ' . $jobId . ', mailing was deleted?');
      return [NULL, NULL];
    }

    return [$mailing_id, $mailing_name];
  }
}
