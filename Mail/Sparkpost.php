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

class Mail_Sparkpost extends Mail {
  /**
   * Send an email
   */
  function send($recipients, $headers, $body) {
    if (defined('CIVICRM_MAIL_LOG')) {
      CRM_Utils_Mail::logger($recipients, $headers, $body);
      if(!defined('CIVICRM_MAIL_LOG_AND SEND')) {
        return true;
      }
    }

    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');

    if (empty($api_key)) {
      throw new Exception('No API key defined for SparkPost');
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE]);

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

    $sp = array(
      'content' => array(),
      'options' => array(
        'open_tracking' => TRUE,  // Even though this will be done by CiviCRM for bulk mailing, If we want to process transactional and to process open and click event by sparkpost
        'click_tracking' => TRUE, // same as above
      ),
    );

    // Should we send via a dedicated IP pool?
    $ip_pool = CRM_Sparkpost::getSetting('sparkpost_ipPool');
    if (!empty($ip_pool)) {
      $sp['options']['ip_pool'] = $ip_pool;
    }

    // Is this a CiviMail mailing or a transactional email?
    if (CRM_Utils_Array::value('X-CiviMail-Bounce', $headers)) {
      // Insert CiviMail header in the outgoing email's metadata
      $sp['metadata'] = array('X-CiviMail-Bounce' => CRM_Utils_Array::value("X-CiviMail-Bounce", $headers));
    }
    else {
      // Mark the email as transactional for SparkPost
      $sp['options']['transactional'] = TRUE;

      // Attach metadata for transactional email
      if (CRM_Utils_Array::value('Return-Path', $headers)) {
        $sp['metadata'] = array('X-CiviMail-Bounce' => CRM_Utils_Array::value("Return-Path", $headers));
      }
    }

    // Attach mailing name as campaign_id for sparkpost
    if (!empty($sp['metadata'])) {
      $metadata = explode(CRM_Core_Config::singleton()->verpSeparator, $sp['metadata']['X-CiviMail-Bounce']);
      list($mailing_id, $mailing_name) = self::getMailing($metadata[1]);

      if ($mailing_name && $mailing_id) {
        $sp['campaign_id'] = $mailing_name . '(' . $mailing_id . ')';

        if (strlen($sp['campaign_id']) > 64) {
          $mailing_name = substr($mailing_name, 0, 64 - (strlen($mailing_id) + 4));
          $sp['campaign_id'] = $mailing_name . '..(' . $mailing_id . ')';
        }
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
    }
    catch (Exception $e) {
      $body = $e->getBody();

      foreach ($body['errors'] as $key => $val) {
        // "recipient address suppressed due to customer policy"
        if ($val['code'] == 1902) {
          $email = $sp['recipients'][0]['address']['email'];
          $status = $sparky->request('GET', 'suppression-list/' . $email);
          sparkpost_log(print_r($status->getBody(), 1));
        }
        else {
          sparkpost_log(print_r($e->getBody(), 1));
          throw new Exception(print_r($recipients, 1) . ' -- ' . print_r($e->getBody(), 1) . ' -- ' . $e->getMessage());
        }
      }
    }

/*
    try {

    $response = $promise->wait();
    // dsm($response->getStatusCode());
    // dsm($response->getBody(), 'body');

    }
    catch (Exception $e) {
      // Check the suppression list status
 #     $status = $sparky->request('GET', 'suppression-list/' . , [
 #       'limit' => '5',
 #     ]);

      throw new Exception($e->getMessage()); //  . ' -- [' . $e->getStatusCode() . '] ' . $e->getBody() . ' -- ' . $e->getAPIDescription() . ' -- ' . print_r($recipients, 1));
    }
*/

  #  return $result;
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
  function formatRecipients($recipients) {
    // CiviCRM passes the recipients as an array of string, each string potentially containing
    // multiple addresses in either abbreviated or full RFC822 format, e.g.
    // $recipients:
    //   [0] nicolas@cividesk.com, Nicolas Ganivet <nicolas@cividesk.com>
    //   [1] "Ganivet, Nicolas" <nicolas@cividesk.com>
    //   [2] ""<nicolas@cividesk.com>,<nicolas@cividesk.com>
    // [0] are the most common cases, [1] note the , inside the quoted name, [2] are edge cases
    // cf. CRM_Utils_Mail::send() lines 161, 171 and 174 (assignments to the $to variable)
    if (!is_array($recipients)) {
      $recipients = array($recipients);
    }
    $result = array();

    foreach ($recipients as $recipientString) {
      // Best is to use the PEAR::Mail package to decapsulate as they have a class just for that!
      $rfc822 = new Mail_RFC822($recipientString);
      $matches = $rfc822->parseAddressList();

      foreach ($matches as $match) {
        $address = array();
        if (!empty($match->mailbox) && !empty($match->host)) {
          $address['email'] =  $match->mailbox . '@' . $match->host;
        }
        if (!empty($match->personal)) {
          if ((substr($match->personal, 0, 1) == '"') && (substr($match->personal, -1) == '"')) {
            $address['name'] = substr($match->personal, 1, -1);
          } else {
            $address['name'] = $match->personal;
          }
        }
        if (!empty($address['email'])) {
          $result[] = array('address' => $address);
        }
      }
    }

    return $result;
  }
  
  static function getMailing($jobId) {
    if (!$jobId) {
      return;
    }

    $mailing_id = CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_MailingJob', $jobId, 'mailing_id');
    $mailing_name = CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_Mailing', $mailing_id, 'name');
    return array($mailing_id, $mailing_name);
  }
}
