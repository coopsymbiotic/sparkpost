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

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use CRM_Sparkpost_ExtensionUtil as E;

class CRM_Sparkpost {
  const SPARKPOST_EXTENSION_SETTINGS = 'SparkPost Extension Settings';
  // Indicates we need to try sending emails out through an alternate method
  const FALLBACK = 1;

  // For converting the Sparkpost 'bounce_class' to CiviCRM's codes
  static public $_civicrm_bounce_types = [
    'Away' => 2,    // soft, retry 30 times
    'Relay' => 9,   // soft, retry 3 times
    'Invalid' => 6, // hard, retry 1 time
    'Spam' => 10,   // hard, retry 1 time
  ];

  // Source: https://support.sparkpost.com/customer/portal/articles/1929896
  // See also: https://docs.civicrm.org/sysadmin/en/latest/setup/civimail/inbound/
  // The CiviCRM equivalent will have a certain threshold before it flags an email On Hold.
  static public $_sparkpost_bounce_types = [
    // Name, Description, Category, CiviCRM equivalent (see above)
     1 => ['Undetermined','The response text could not be identified.','Undetermined', ''],
    10 => ['Invalid Recipient','The recipient is invalid.','Hard', 'Invalid'],
    20 => ['Soft Bounce','The message soft bounced.','Soft', 'Relay'],
    21 => ['DNS Failure','The message bounced due to a DNS failure.','Soft', 'Relay'],
    22 => ['Mailbox Full','The message bounced due to the remote mailbox being over quota.','Soft', 'Away'],
    23 => ['Too Large','The message bounced because it was too large for the recipient.','Soft', 'Away'],
    24 => ['Timeout','The message timed out.','Soft', 'Relay'],
    25 => ['Admin Failure', 'The message was failed by SparkPost\'s configured policies.', 'Admin', 'Invalid'],
    30 => ['Generic Bounce: No RCPT','No recipient could be determined for the message.','Hard', 'Invalid'],
    40 => ['Generic Bounce','The message failed for unspecified reasons.','Soft', 'Relay'],
    50 => ['Mail Block','The message was blocked by the receiver.','Block', 'Spam'],
    51 => ['Spam Block','The message was blocked by the receiver as coming from a known spam source.','Block', 'Spam'],
    52 => ['Spam Content','The message was blocked by the receiver as spam.','Block', 'Spam'],
    53 => ['Prohibited Attachment','The message was blocked by the receiver because it contained an attachment.','Block', 'Spam'],
    54 => ['Relaying Denied','The message was blocked by the receiver because relaying is not allowed.','Block', 'Relay'],
    60 => ['Auto-Reply','The message is an auto-reply/vacation mail.','Soft', 'Away'],
    70 => ['Transient Failure','Message transmission has been temporarily delayed.','Soft', 'Relay'],
    80 => ['Subscribe','The message is a subscribe request.','Admin', ''],
    90 => ['Unsubscribe','The message is an unsubscribe request.','Hard', 'Spam'],
   100 => ['Challenge-Response','The message is a challenge-response probe.','Soft', ''],
  ];


  public static function setSetting($setting, $value) {
    // Encrypt API key before storing in database
    if ($setting == 'sparkpost_apiKey') {
      $value = CRM_Utils_Crypt::encrypt($value);
    }
    return Civi::settings()->set($setting, $value);
  }

  public static function getSetting($setting = NULL) {
    // Start with the default values for settings
    $settings = array(
      'sparkpost_useBackupMailer' => false,
      'sparkpost_host' => 'sparkpost.com',
    );
    // Merge the settings defined in DB (no more groups in 4.7, so has to be one by one ...)
    foreach (array('sparkpost_apiKey', 'sparkpost_useBackupMailer', 'sparkpost_campaign', 'sparkpost_ipPool', 'sparkpost_customCallbackUrl', 'sparkpost_host', 'sparkpost_sending_quota', 'sparkpost_sending_quota_alert', 'sparkpost_bounce_rate') as $name) {
      $value = Civi::settings()->get($name);
      if (!is_null($value)) {
        $settings[$name] = $value;
      }
    }
    // Decrypt API key before returning
    // It might not be encrypted, if the key was loaded using a global PHP settings file
    if (!preg_match('/^[0-9A-Za-z]+$/', $settings['sparkpost_apiKey'])) {
      $settings['sparkpost_apiKey'] = CRM_Utils_Crypt::decrypt($settings['sparkpost_apiKey']);
    }
    // And finaly returm what was asked for ...
    if (!empty($setting)) {
      return CRM_Utils_Array::value($setting, $settings);
    }
    else {
      return $settings;
    }
  }

  /**
   * Returns the CiviCRM localpart for the current domain.
   */
  static function getDomainLocalpart() {
    $dao = new CRM_Core_DAO_MailSettings;
    $dao->domain_id = CRM_Core_Config::domainID();
    $dao->is_default = TRUE;

    if ($dao->find(TRUE)) {
      return $dao->localpart;
    }

    return NULL;
  }

  /**
   *
   */
  static function getPartsFromBounceID($civimail_bounce_id) {
    // Extract CiviMail parameters from header value
    // NB: the localpart might be empty, but the regexp should still work.
    $localpart = static::getDomainLocalpart();
    $rpRegex = '/^' . preg_quote($localpart) . '(b|c|e|o|r|u|m)\.(\d+)\.(\d+)\.([0-9a-f]{16})/';

    if (preg_match($rpRegex, $civimail_bounce_id, $matches)) {
      return [
        'action' => $matches[1],
        'job_id' => $matches[2],
        'event_queue_id' => $matches[3],
        'hash' => $matches[4],
      ];
    }

    Civi::log()->warning('SparkPost getPartsFromBounceID failed with {bounce_id} and localpart {localpart}', [
      'bounce_id' => $civimail_bounce_id,
      'localpart' => $localpart,
    ]);

    return NULL;
  }

  /**
   * Verifies and caches the list of verified sending domains.
   */
  public static function getSparkpostVerifiedSendingDomains() {
    $domains = Civi::cache('long')->get('sparkpost_verifiedsendingdomains');

    if (!empty($domains)) {
      return $domains;
    }

    $domains = [];

    // TODO: Has some duplication with CRM_Sparkpost_Utils_Check_SendingDomains::check()
    require_once __DIR__ . '/../vendor/autoload.php';
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');
    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    try {
      $response = $sparky->request('GET', 'sending-domains', [
        'ownership_verified' => true,
      ]);

      $body = $response->getBody();
      $domains = [];

      foreach ($body['results'] as $key => $val) {
        $domains[] = $val['domain'];
      }
    }
    catch (Exception $e) {
      $code = $e->getCode();
      $body = $e->getBody();

      CRM_Core_Session::setStatus(E::ts('The email was not sent. Failed to get the valid sending domains from Sparkpost: code %1, error: %2', [
        1 => $e->getCode(),
        2 => $e->getMessage() . ' / ' . print_r($body, 1),
      ]), E::ts('Email not sent'), 'error');

      // nb: for now, we are not returning early, and caching this error.
      // The email will still fail to validate against a verified domain.
    }

    Civi::cache('long')->set('sparkpost_verifiedsendingdomains', $domains);
    return $domains;
  }

  /**
   *
   */
  public static function isValidSparkpostVerifiedSendingEmail($email) {
    $domains = self::getSparkpostVerifiedSendingDomains();

    // I guess there are a few ways of doing this. We could also extract the domain from the email,
    // but this can be buggy. So for now, just checking if an allowed domain name is part of the email.
    // Ex: is "example.org" part of "Alice <alice@example.org>".
    foreach ($domains as $domain) {
      if (strpos($email, $domain) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * @return array
   *   Array(string $value => string $label).
   */
  public static function getSparkpostHostOptions() {
    return array(
      'sparkpost.com' => ts('Default'),
      'eu.sparkpost.com' => ts('Europe'),
    );
  }

  /**
   * Calls the SparkPost REST API v1
   * @param $path    Method path
   * @param $params  Method parameters (translated as GET arguments)
   * @param $content Method content (translated as POST arguments)
   *
   * @see https://developers.sparkpost.com/api/
   *
   * @deprecated Use CRM_Sparkpost::request()
   */
  public static function call($path, $params = array(), $content = array()) {
    // Get the API key from the settings
    $authorization = static::getSetting('sparkpost_apiKey');
    if (empty($authorization)) {
      throw new Exception('No API key defined for SparkPost');
    }

    // Deal with the campaign setting
    if (($path =='transmissions') && ($campaign = static::getSetting('sparkpost_campaign'))) {
      $content['campaign_id'] = $campaign;
    }

    // Initialize connection and set headers
    $sparkpost_host = static::getSetting('sparkpost_host');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.$sparkpost_host/api/v1/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $request_headers = array();
    $request_headers[] = 'Content-Type: application/json';
    $request_headers[] = 'Authorization: ' . $authorization;
    $request_headers[] = 'User-Agent: CiviCRM SparkPost extension (com.cividesk.email.sparkpost)';
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

    if (!empty($content)) {
      if (strpos($path, '/') !== false) {
        // ie. webhook/id
        // This is a modify operation so use PUT
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
      } else {
        // ie. webhook, transmission
        // This is a create operation so use POST
        curl_setopt($ch, CURLOPT_POST, TRUE);
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content, JSON_UNESCAPED_SLASHES));
    }
    elseif (substr($path, 0, strlen('suppression-list')) === 'suppression-list') {
      // delete email from sparkpost suppression list
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    }
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
      throw new Exception('Sparkpost curl error: '. curl_error($ch));
    }
    $curl_info = curl_getinfo($ch);
    curl_close($ch);

    // Treat errors if any in the response ...
    $response = json_decode($data);
    if (isset($response->errors) && is_array($response->errors)) {
      // Log this error for debugging purposes
      Civi::log()->error('==== ERROR in CRM_Sparkpost::call() ====');
      Civi::log()->error(print_r($response, TRUE));
      Civi::log()->error(print_r($content, TRUE));

      $error = reset($response->errors);

      // See issue #5: http_code is more dicriminating than $error->message
      // https://support.sparkpost.com/customer/en/portal/articles/2140916-extended-error-codes
      if (!in_array($curl_info['http_code'], array(
        204, // HTTP status of 204 indicates a successful deletion
      ))) {
        switch ($curl_info['http_code']) {
          case 400 :
            switch ($error->code) {
              // Did the email bounce because one of the recipients is on the SparkPost rejection list?
              // https://support.sparkpost.com/customer/portal/articles/2110621-sending-messages-to-recipients-on-the-exclusion-list
              // AFAIK there can be multiple recipients and we don't know which caused the bounce, so cannot really do anything
              case 1901:
                throw new Exception("Sparkpost error: At least one recipient is on the Sparkpost Exclusion List for non-transactional emails.");
              case 1902:
                throw new Exception("Sparkpost error: At least one recipient is on the Sparkpost Exclusion List for transactional emails.");
              case 7001:
                throw new Exception("Sparkpost error: The sending or tracking domain is unconfigured or unverified in Sparkpost.", CRM_Sparkpost::FALLBACK);
            }
            break;
          case 401 :
            throw new Exception("Sparkpost error: Unauthorized. Check that the API key is valid, and allows IP $curl_info[local_ip].", CRM_Sparkpost::FALLBACK);
          case 403 :
            throw new Exception("Sparkpost error: Permission denied. Check that the API key is authorized for request $curl_info[url].", CRM_Sparkpost::FALLBACK);
          case 420 :
            throw new Exception("Sparkpost error: Sending limits exceeded. Check your limits in the Sparkpost console.", CRM_Sparkpost::FALLBACK);
        }
        //If we don't get an error code OR message, this error is a 404 response for checking the suppression list for an email that isn't on it. See https://developers.sparkpost.com/api/suppression-list/#suppression-list-get-retrieve-a-suppression
        //In that case it's an expected workflow and we don't want to return a blank error response to the user just for checking
        if (property_exists($error, 'code') && property_exists($error, 'message')) {
          // Don't have specifics, so throw a generic exception
          throw new Exception("Sparkpost error: HTTP return code $curl_info[http_code], Sparkpost error code $error->code ($error->message). Check https://support.sparkpost.com/customer/en/portal/articles/2140916-extended-error-codes for interpretation.");
        }
      }
    }

    // Return (valid) response
    return $response;
  }

  /**
   * Wrapper around the Sparkpost API
   */
  public static function request(String $method, String $uri, Array $params = []) {
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');

    require_once __DIR__ . '/../vendor/autoload.php';
    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    // Admitedly not ideal, since it assumes that the caller will not forget about paging
    // but we are only using this for fetching events for now, so it's not a big deal.
    if (!empty(\Civi::$statics[__CLASS__]['cursor'])) {
      // The Sparkpost PHP library does not like it if we pass the cursor in the 'uri' param
      // Sparkpost will throw a 403 Forbidden. We parse_str the cursor, and remove the 'uri' part.
      $cursor = substr(\Civi::$statics[__CLASS__]['cursor'], strlen('/api/v1/events/message?'));
      parse_str($cursor, $params);
    }

    $response = $sparky->request($method, $uri, $params);
    $body = $response->getBody();

    // Track paging. If there is no more data, the next will be empty.
    \Civi::$statics[__CLASS__]['cursor'] = $body['links']['next'] ?? null;

    return $body['results'];
  }

  /**
   * Creates the special Mailing for tracking transactional emails
   *
   * @return Boolean
   *   True if the mailing was created.
   */
  public static function createTransactionalMailing() {
    $result = civicrm_api3('Mailing', 'get', [
      'name' => 'Sparkpost Transactional Emails',
    ]);

    if (empty($result['values'])) {
      // Create entry in civicrm_mailing
      $mailingParams = [
        'subject' => E::ts('Sparkpost Transactional Emails (do not delete)'),
        'name' => 'Sparkpost Transactional Emails',
        'url_tracking' => TRUE,
        'forward_replies' => FALSE,
        'auto_responder' => FALSE,
        'open_tracking' => TRUE,
        'is_completed' => FALSE,
      ];

      $mailing = CRM_Mailing_BAO_Mailing::add($mailingParams);

      // Add entry in civicrm_mailing_job
      $saveJob = new CRM_Mailing_DAO_MailingJob();
      $saveJob->start_date = $saveJob->end_date = date('YmdHis');
      $saveJob->status = 'Complete';
      $saveJob->job_type = "Special: All Sparkpost transactional emails";
      $saveJob->mailing_id = $mailing->id;
      $saveJob->save();

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Process Sparkpost event
   *
   * This is used by the callback webhook, and by this extension's Sparkpost.event API
   */
  public static function processSparkpostEvent(Array $event) {
    // Sanity checks
    if (!in_array($event['type'], ['bounce', 'spam_complaint', 'policy_rejection', 'list_unsubscribe'])) {
      return;
    }
    if (empty($event['rcpt_meta']['X-CiviMail-Bounce'])) {
      return;
    }

    $civimail_bounce_id = $event['rcpt_meta']['X-CiviMail-Bounce'];

    // Extract CiviMail parameters from header value
    $header = CRM_Sparkpost::getPartsFromBounceID($civimail_bounce_id);

    if (empty($header)) {
      Civi::log()->error('Failed to parse the email bounce ID {header}', [
        'header' => $civimail_bounce_id,
      ]);
      return;
    }

    require_once 'Mail/sparkpost.php';
    list($mailing_id, $mailing_name ) = Mail_sparkpost::getMailing($header['job_id']);

    if (!$mailing_id) {
      Civi::log()->warning('No mailing found for {matches} hence skiping in SparkPost extension call back', [
        'event' => $event,
      ]);
      return;
    }

    $params = [
      'job_id' => $header['job_id'],
      'event_queue_id' => $header['event_queue_id'],
      'hash' => $header['hash'],
    ];

    // Was SparkPost able to classify the message?
    if (in_array($event['type'], ['spam_complaint','policy_rejection'])) {
      $params['bounce_type_id'] = self::$_civicrm_bounce_types['Spam'];
      $params['bounce_reason'] = $event['reason'] ?: 'Message has been flagged as Spam by the recipient';
    }
    elseif ($event['type'] == 'bounce') {
      $sparkpost_bounce = CRM_Utils_Array::value($event['bounce_class'], self::$_sparkpost_bounce_types);
      $params['bounce_type_id'] = CRM_Utils_Array::value($sparkpost_bounce[3], self::$_civicrm_bounce_types);
      $params['bounce_reason'] = $event['reason'];
    }
    elseif ($event['type'] == 'open' || $event['type'] == 'click') {
      switch ($event['type']) {
        case 'open':
          if ($header['action'] == 'b') {
            // Civi Mailing do not process as done by CiviCRM
            break;
          }
          $oe = new CRM_Mailing_Event_BAO_Opened();
          $oe->event_queue_id = $header['event_queue_id'];
          $oe->time_stamp = date('YmdHis', $event['timestamp']);
          $oe->save();
          break;

        case 'click':
          if ($header['action'] == 'b') {
            // Civi Mailing do not process as done by CiviCRM
            break;
          }
          $tracker = new CRM_Mailing_BAO_TrackableURL();
          $tracker->url = $event['target_link_url'];
          $tracker->mailing_id = $mailing_id;
          if (!$tracker->find(TRUE)) {
            $tracker->save();
          }
          $open = new CRM_Mailing_Event_BAO_TrackableURLOpen();
          $open->event_queue_id = $header['event_queue_id'];
          $open->trackable_url_id = $tracker->id;
          $open->time_stamp = date('YmdHis', $event['timestamp']);
          $open->save();
          break;
      }
    }

    if (!empty($params['bounce_type_id'])) {
      if ($params['bounce_type_id'] == 10) {
        // Don't create entries for spam bounces as this only puts the email on hold, opt out the contact instead.
        // This is because the contact likely reported the email as spam as a way to unsubscribe.
        // So opting out only the one email adress instead of the contact risks getting any emails  sent to their
        // secondary adresses flagged as spam as well, which can hurt our spam score.
        $sql = "SELECT cc.id FROM civicrm_contact cc INNER JOIN civicrm_mailing_event_queue cmeq ON cmeq.contact_id = cc.id WHERE cmeq.id = %1";

        $contact_id = CRM_Core_DAO::singleValueQuery($sql, [
          1 => [$params['event_queue_id'], 'Integer'],
        ]);

        if (!empty($contact_id)) {
          $result = civicrm_api3('Contact', 'create', [
            'id' => $contact_id,
            'is_opt_out' => 1,
          ]);
        }
      }
      else {
        CRM_Mailing_Event_BAO_MailingEventBounce::recordBounce($params);
      }
    }
    elseif (in_array($event['type'], ['spam_complaint', 'policy_rejection', 'bounce'])) {
      // Sparkpost was not able to detmine the bounce type, so let CiviCRM have a go at classifying it
      $params['body'] = $event['raw_reason'] ?? $event['type'];
      civicrm_api3('Mailing', 'event_bounce', $params);
    }
    elseif ($event['type'] == 'list_unsubscribe') {
      $params['body'] = $event['raw_reason'] ?? $event['type'];
      CRM_Mailing_Event_BAO_Unsubscribe::unsub_from_mailing($header['job_id'], $header['event_queue_id'], $header['hash']);
    }
  }

}
