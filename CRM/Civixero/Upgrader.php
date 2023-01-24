<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Civixero_Upgrader extends CRM_Extension_Upgrader_Base {

  private function updateLegacySettings() {
    $token = \Civi::settings()->get('xero_access_token');
    if (!empty($token)) {
      Civi::settings()->set('xero_access_token_refresh_token', $token['refresh_token'] ?? '');
      Civi::settings()->set('xero_access_token_access_token', $token['access_token'] ?? '');
      Civi::settings()->set('xero_access_token_expires', $token['expires'] ?? '');
      Civi::settings()->set('xero_access_token', NULL);
    }
  }

  /**
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1001() {
    $this->ctx->log->info('Update legacy settings');
    $this->updateLegacySettings();
    return TRUE;
  }

}
