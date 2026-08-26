<?php
class CRM_M365GroupSync_Upgrader extends CRM_Extension_Upgrader_Base {
  private const SETTING_NAMES = [
    'm365_group_sync_enabled',
    'm365_group_sync_retention_days',
    'm365_group_sync_auth_method',
    'm365_group_sync_tenant_id',
    'm365_group_sync_client_id',
    'm365_group_sync_client_secret',
    'm365_group_sync_access_token',
    'm365_group_sync_refresh_token',
    'm365_group_sync_token_expires',
    'm365_group_sync_delegated_binding',
    'm365_group_sync_application_binding',
    'm365_group_sync_connected_user',
    'm365_group_sync_connected_tenant',
    'm365_group_sync_last_auth',
    'm365_group_sync_invite_redirect_url',
    'm365_group_sync_last_auto_enqueue',
    'm365_group_sync_last_cleanup',
  ];

  public static function install(): void {
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_group_mapping (civicrm_group_id INT UNSIGNED NOT NULL, m365_group_id VARCHAR(128) NOT NULL, m365_display_name VARCHAR(255) NULL, m365_mail VARCHAR(255) NULL, created_date DATETIME NOT NULL, modified_date DATETIME NOT NULL, PRIMARY KEY (civicrm_group_id), UNIQUE KEY m365_group_id (m365_group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_run (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id CHAR(36) NOT NULL, operation_id CHAR(36) NULL, civicrm_group_id INT UNSIGNED NOT NULL, m365_group_id VARCHAR(128) NOT NULL, mode VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL DEFAULT 'manual', status VARCHAR(32) NOT NULL, phase VARCHAR(16) NOT NULL DEFAULT 'complete', total_items INT UNSIGNED NOT NULL DEFAULT 0, processed_items INT UNSIGNED NOT NULL DEFAULT 0, error_items INT UNSIGNED NOT NULL DEFAULT 0, retry_count INT UNSIGNED NOT NULL DEFAULT 0, cancel_requested TINYINT(1) NOT NULL DEFAULT 0, started_date DATETIME NOT NULL, heartbeat_date DATETIME NULL, next_retry_date DATETIME NULL, completed_date DATETIME NULL, summary LONGTEXT NULL, PRIMARY KEY (id), UNIQUE KEY run_id (run_id), KEY operation_id (operation_id), KEY group_date (civicrm_group_id, started_date), KEY worker (status,next_retry_date,heartbeat_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_work (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id CHAR(36) NOT NULL, item_type VARCHAR(16) NOT NULL, phase VARCHAR(16) NOT NULL, state VARCHAR(16) NOT NULL, civicrm_contact_id INT UNSIGNED NULL, effective_email VARCHAR(255) NULL, m365_user_id VARCHAR(128) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, available_date DATETIME NULL, message TEXT NULL, created_date DATETIME NOT NULL, modified_date DATETIME NOT NULL, PRIMARY KEY (id), KEY run_phase_state (run_id,phase,state,available_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id CHAR(36) NOT NULL, civicrm_group_id INT UNSIGNED NOT NULL, civicrm_contact_id INT UNSIGNED NULL, effective_email VARCHAR(255) NULL, action VARCHAR(32) NOT NULL, result VARCHAR(16) NOT NULL, message TEXT NULL, m365_group_id VARCHAR(128) NULL, m365_user_id VARCHAR(128) NULL, created_date DATETIME NOT NULL, PRIMARY KEY (id), KEY run_group (run_id, civicrm_group_id), KEY contact_email (civicrm_contact_id, effective_email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Civi::settings()->set('m365_group_sync_enabled', FALSE); Civi::settings()->set('m365_group_sync_retention_days', 90);
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_job (domain_id,run_frequency,name,description,api_entity,api_action,parameters,is_active) SELECT 1,'Always','Microsoft 365 Group Membership Sync','Process queued work and enqueue automatic reconciliation hourly','M365GroupSync','scheduled',%1,1 WHERE NOT EXISTS (SELECT 1 FROM civicrm_job j WHERE j.api_entity='M365GroupSync' AND j.api_action='scheduled')",
      [1 => ["version=4", 'String']]
    );
  }

  public static function uninstall(): void {
    // Stop future runs before removing credentials, mappings, and history.
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_job WHERE api_entity = %1 AND api_action = %2',
      [1 => ['M365GroupSync', 'String'], 2 => ['scheduled', 'String']]
    );

    // Physically delete every extension setting across domains. This removes
    // locally stored ciphertext for the client secret and OAuth tokens as well
    // as connection metadata and operational preferences.
    foreach (self::SETTING_NAMES as $name) {
      CRM_Core_DAO::executeQuery(
        'DELETE FROM civicrm_setting WHERE name = %1',
        [1 => [$name, 'String']]
      );
    }

    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_m365_sync_work');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_m365_sync_log');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_m365_sync_run');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_m365_group_mapping');
  }

  public function upgrade_1001() {
    // Existing 1.0.0 installs need the settings metadata and refreshed menu routes.
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    $this->ctx->log->info('Registered Microsoft OAuth callback and expanded synchronization dashboard.');
    return TRUE;
  }

  public function upgrade_1002() {
    $columns = [
      'operation_id' => 'CHAR(36) NULL AFTER run_id',
      'source' => "VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER mode",
      'phase' => "VARCHAR(16) NOT NULL DEFAULT 'complete' AFTER status",
      'total_items' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER phase',
      'processed_items' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_items',
      'error_items' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER processed_items',
      'retry_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER error_items',
      'cancel_requested' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER error_items',
      'heartbeat_date' => 'DATETIME NULL AFTER started_date',
      'next_retry_date' => 'DATETIME NULL AFTER heartbeat_date',
    ];
    foreach ($columns as $name => $definition) {
      if (!self::columnExists('civicrm_m365_sync_run', $name)) {
        CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_m365_sync_run ADD COLUMN `$name` $definition");
      }
    }
    if (!self::indexExists('civicrm_m365_sync_run', 'operation_id')) {
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_m365_sync_run ADD KEY operation_id (operation_id)');
    }
    if (!self::indexExists('civicrm_m365_sync_run', 'worker')) {
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_m365_sync_run ADD KEY worker (status,next_retry_date,heartbeat_date)');
    }
    CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET operation_id=run_id WHERE operation_id IS NULL OR operation_id=''");
    // Legacy synchronous runs cannot be resumed because they have no durable
    // work rows. Do not let an interrupted legacy run block its group forever.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status='error',completed_date=COALESCE(completed_date,NOW()) WHERE status='running' AND phase='complete'");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_work (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id CHAR(36) NOT NULL, item_type VARCHAR(16) NOT NULL, phase VARCHAR(16) NOT NULL, state VARCHAR(16) NOT NULL, civicrm_contact_id INT UNSIGNED NULL, effective_email VARCHAR(255) NULL, m365_user_id VARCHAR(128) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, available_date DATETIME NULL, message TEXT NULL, created_date DATETIME NOT NULL, modified_date DATETIME NOT NULL, PRIMARY KEY (id), KEY run_phase_state (run_id,phase,state,available_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_job SET run_frequency='Always',description='Process queued work and enqueue automatic reconciliation hourly' WHERE api_entity='M365GroupSync' AND api_action='scheduled'");
    $this->ctx->log->info('Installed resumable Microsoft 365 synchronization queue.');
    return TRUE;
  }

  public function upgrade_1003() {
    CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
    $authMethod = (string) (Civi::settings()->get('m365_group_sync_auth_method') ?: 'delegated');
    if ($authMethod === 'delegated' && (string) Civi::settings()->get('m365_group_sync_refresh_token') !== '') {
      // Older tokens were not cryptographically bound to the configured tenant
      // and client. Require one deliberate reconnect rather than trusting that
      // the current form values still describe the authorization that issued it.
      (new CRM_M365GroupSync_Service_Auth())->disconnect();
      $this->ctx->log->warning('Delegated Microsoft authorization must be reconnected after the 1.2.1 security upgrade.');
    }
    elseif ($authMethod === 'application') {
      Civi::settings()->set('m365_group_sync_application_binding', '');
      foreach (['m365_group_sync_connected_user', 'm365_group_sync_connected_tenant', 'm365_group_sync_last_auth'] as $name) {
        Civi::settings()->set($name, '');
      }
      $this->ctx->log->warning('Microsoft application credentials must pass Test Connection after the 1.2.1 security upgrade.');
    }
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    $this->ctx->log->info('Installed dedicated Microsoft 365 permissions and credential-binding safeguards.');
    return TRUE;
  }

  public function upgrade_1004() {
    // Discover the new APIv4 entity and interactive actions.
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    $this->ctx->log->info('Registered Microsoft 365 Group Sync APIv4 actions.');
    return TRUE;
  }

  public function upgrade_1005() {
    // Preserve the existing job's ID, enabled state, schedule, and run history.
    // CiviCRM cron authenticates as the configured cron user, which must have
    // the extension's administration permission.
    CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_job SET parameters = %1 WHERE api_entity = %2 AND api_action = %3',
      [
        1 => ["version=4", 'String'],
        2 => ['M365GroupSync', 'String'],
        3 => ['scheduled', 'String'],
      ]
    );
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    $this->ctx->log->info('Migrated Microsoft 365 Group Sync scheduled job to APIv4.');
    return TRUE;
  }

  private static function columnExists(string $table, string $column): bool {
    return (bool) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%1 AND column_name=%2',
      [1 => [$table, 'String'], 2 => [$column, 'String']]
    );
  }

  private static function indexExists(string $table, string $index): bool {
    return (bool) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=%1 AND index_name=%2',
      [1 => [$table, 'String'], 2 => [$index, 'String']]
    );
  }
}
