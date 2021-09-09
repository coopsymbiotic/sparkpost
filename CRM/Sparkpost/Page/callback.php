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

class CRM_Sparkpost_Page_callback extends CRM_Core_Page {

  function run() {
    // The $_POST variable does not work because this is json data
    $postdata = file_get_contents("php://input");
    $elements = json_decode($postdata, true);

    foreach ($elements as $element) {
      if (empty($element['msys'])) {
        continue;
      }

      // @todo Is this still relevant?
      $event = $element['msys']['message_event'] ??  $element['msys']['track_event'] ?? null;

      if ($event) {
        CRM_Sparkpost::processSparkpostEvent($event);
      }
    }

    CRM_Utils_System::civiExit();
  }

}
