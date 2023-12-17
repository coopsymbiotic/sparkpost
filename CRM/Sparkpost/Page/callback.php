<?php

class CRM_Sparkpost_Page_callback extends CRM_Core_Page {

  public function run() {
    // The $_POST variable does not work because this is json data
    $postdata = file_get_contents("php://input");
    $elements = json_decode($postdata, TRUE);

    foreach ($elements as $element) {
      if (empty($element['msys'])) {
        continue;
      }

      // @todo Is this still relevant?
      $event = $element['msys']['message_event'] ?? $element['msys']['track_event'] ?? NULL;

      if ($event) {
        CRM_Sparkpost::processSparkpostEvent($event);
      }
    }

    CRM_Utils_System::civiExit();
  }

}
