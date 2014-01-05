<?php

class CRM_Xerosync_Contact extends CRM_Xerosync_Base {

  function pull($params) {
    $result = CRM_Xerosync_Base::singleton()->Contacts(false, $this->formatDateForXero($params['start_date']));
    if(!is_array($result)){
      throw new API_Exception('Sync Failed', 'xero_retrieve_failure', $result);
    }
    if ($result['Contacts']){
      CRM_Core_Session::setStatus(count($result['Contacts'] . ts(' retrieved')), ts('Contact Pull'));
      foreach($result['Contacts']['Contact'] as $contact){
        $params = array();
        try {
          $params['id'] = civicrm_api3('account_contact', 'getvalue', array(
            'return' => 'id',
            'accounts_contact_id' => $contact['ContactID'],
            'plugin' => $this->_plugin,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          // this is an update
        }
        $params['accounts_display_name'] = $contact['Name'];
        $params['contact_id'] = $contact['ContactNumber'];
        $params['accounts_modified_date'] = $contact['UpdatedDateUTC'];
        $params['plugin'] = 'xero';
        $params['accounts_contact_id'] = $contact['ContactID'];
        $params['accounts_data'] = json_encode($contact);
        try {
          $result = civicrm_api3('account_contact', 'create', $params);
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Session::setStatus(ts('Failed to store ') . $params['accounts_display_name']
          . ts(' with error ') . $e->getMessage()
          , ts('Contact Pull failed'));
        }
      }
    }
  }
}