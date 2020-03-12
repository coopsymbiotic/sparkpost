<?php

class CRM_Sparkpost_Contact_Page_View_Summary {

  /**
   * @see sparkpost_civicrm_pageRun().
   */
  static public function pageRun(&$page) {
    $smarty = CRM_Core_Smarty::singleton();
    $contact_id = $smarty->_tpl_vars['contactId'];

    $result = civicrm_api3('Email', 'get', [
      'contact_id' => $contact_id,
    ]);

    if (!empty($result['values'])) {
      // Dedupe emails, since we often have duplicate home/billing emails
      $seen = $emails = [];

      foreach ($result['values'] as $key => $val) {
        if (!in_array($val['email'], $seen)) {
          $seen[] = $val['email'];
          $emails[] = $val;
        }
      }

      $page->assign('sparkpost_emails', $emails);

      CRM_Core_Region::instance('page-body')->add(array(
        'template' => 'CRM/Sparkpost/Contact/Page/View/Summary-diagnostics.tpl',
      ));
    }
  }

}
