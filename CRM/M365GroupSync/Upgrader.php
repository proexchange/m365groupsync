<?php

class CRM_M365GroupSync_Upgrader extends CRM_Extension_Upgrader_Base {
  private const SETTING_NAMES = [
    'm365_group_sync_enabled', 'm365_group_sync_retention_days', 'm365_group_sync_auth_method',
    'm365_group_sync_tenant_id', 'm365_group_sync_client_id', 'm365_group_sync_client_secret',
    'm365_group_sync_access_token', 'm365_group_sync_refresh_token', 'm365_group_sync_token_expires',
    'm365_group_sync_delegated_binding', 'm365_group_sync_application_binding',
    'm365_group_sync_connected_user', 'm365_group_sync_connected_tenant', 'm365_group_sync_last_auth',
    'm365_group_sync_invite_redirect_url', 'm365_group_sync_last_auto_enqueue',
    'm365_group_sync_last_cleanup', 'm365_group_sync_automatic_cadence',
    'm365_group_sync_legacy_domain_resolution_required',
  ];

  public static function install(): void {
    self::createFinalTables();
    Civi::settings()->set('m365_group_sync_enabled', FALSE);
    Civi::settings()->set('m365_group_sync_retention_days', 90);
    Civi::settings()->set('m365_group_sync_automatic_cadence', 'Hourly');
    self::ensureScheduledJob(CRM_M365GroupSync_Service_Domain::id());
  }

  public static function uninstall(): void {
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_job WHERE api_entity=%1 AND api_action=%2', [1 => ['M365GroupSync', 'String'], 2 => ['scheduled', 'String']]);
    foreach (self::SETTING_NAMES as $name) CRM_Core_DAO::executeQuery('DELETE FROM civicrm_setting WHERE name=%1', [1 => [$name, 'String']]);
    // Entity declarations are metadata only. The upgrader owns physical lifecycle.
    foreach (['civicrm_m365_sync_work', 'civicrm_m365_sync_log', 'civicrm_m365_sync_run', 'civicrm_m365_group_mapping'] as $table) CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS `$table`");
  }

  /** Creates or refreshes exactly one worker job for a CiviCRM domain. */
  public static function ensureScheduledJob(int $domainId): void {
    $id = (int) CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_job WHERE domain_id=%1 AND api_entity=%2 AND api_action=%3 ORDER BY id LIMIT 1', [1 => [$domainId, 'Positive'], 2 => ['M365GroupSync', 'String'], 3 => ['scheduled', 'String']]);
    if ($id) {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_job SET run_frequency='Always',description=%1,parameters=%2 WHERE id=%3", [1 => [ts('Process queued Microsoft 365 work; automatic reconciliation follows this domain’s cadence.'), 'String'], 2 => ["version=4", 'String'], 3 => [$id, 'Positive']]);
      // Do not overwrite is_active: an administrator may have disabled it.
      return;
    }
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_job (domain_id,run_frequency,name,description,api_entity,api_action,parameters,is_active) VALUES (%1,'Always',%2,%3,'M365GroupSync','scheduled',%4,1)", [1 => [$domainId, 'Positive'], 2 => [ts('Microsoft 365 Group Membership Sync'), 'String'], 3 => [ts('Process queued Microsoft 365 work; automatic reconciliation follows this domain’s cadence.'), 'String'], 4 => ["version=4", 'String']]);
  }

  /** Finalize a current-domain legacy-data claim after explicit administrator confirmation. */
  public static function claimLegacyDataForCurrentDomain(): void {
    $domainId = CRM_M365GroupSync_Service_Domain::id();
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_group_mapping SET domain_id=%1 WHERE domain_id IS NULL', [1 => [$domainId, 'Positive']]);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET domain_id=%1 WHERE domain_id IS NULL', [1 => [$domainId, 'Positive']]);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_log SET domain_id=%1 WHERE domain_id IS NULL', [1 => [$domainId, 'Positive']]);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_work w INNER JOIN civicrm_m365_sync_run r ON r.run_id=w.run_id SET w.domain_id=r.domain_id WHERE w.domain_id IS NULL');
    foreach (['civicrm_m365_group_mapping', 'civicrm_m365_sync_run', 'civicrm_m365_sync_log', 'civicrm_m365_sync_work'] as $table) {
      if ((int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM `$table` WHERE domain_id IS NULL")) throw new CRM_Core_Exception(ts('Legacy Microsoft 365 records could not be assigned to this domain.'));
      CRM_Core_DAO::executeQuery("ALTER TABLE `$table` MODIFY domain_id INT UNSIGNED NOT NULL");
    }
    self::migrateLegacyJob($domainId);
    self::ensureScheduledJob($domainId);
    Civi::settings()->set('m365_group_sync_legacy_domain_resolution_required', FALSE);
  }

  public function upgrade_1001() { CRM_Core_Invoke::rebuildMenuAndCaches(TRUE); return TRUE; }
  public function upgrade_1002() { return TRUE; }
  public function upgrade_1003() { return TRUE; }
  public function upgrade_1004() { CRM_Core_Invoke::rebuildMenuAndCaches(TRUE); return TRUE; }
  public function upgrade_1005() { return TRUE; }

  /** Add domain ownership without destroying legacy data. */
  public function upgrade_1006() {
    CRM_M365GroupSync_Service_Sync::requestCancellationForAll(TRUE);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET state='cancelled',modified_date=NOW() WHERE state IN ('pending','retry')");
    foreach (['civicrm_m365_group_mapping', 'civicrm_m365_sync_run', 'civicrm_m365_sync_log', 'civicrm_m365_sync_work'] as $table) if (!self::columnExists($table, 'domain_id')) CRM_Core_DAO::executeQuery("ALTER TABLE `$table` ADD COLUMN domain_id INT UNSIGNED NULL");
    self::addMappingId(); self::addDomainIndexes();
    if ((int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_domain') <= 1) {
      self::claimLegacyDataForCurrentDomain();
      $this->ctx->log->info('Assigned legacy Microsoft 365 sync data to the sole CiviCRM domain.');
    }
    else {
      Civi::settings()->set('m365_group_sync_legacy_domain_resolution_required', TRUE);
      $this->ctx->log->warning('Microsoft 365 sync data needs explicit current-domain ownership confirmation before synchronization resumes.');
    }
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    return TRUE;
  }

  public function upgrade_1007() {
    if (!CRM_M365GroupSync_Service_Domain::isLegacyResolutionRequired()) self::ensureScheduledJob(CRM_M365GroupSync_Service_Domain::id());
    CRM_Core_Invoke::rebuildMenuAndCaches(TRUE);
    return TRUE;
  }

  private static function createFinalTables(): void {
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_group_mapping (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,domain_id INT UNSIGNED NOT NULL,civicrm_group_id INT UNSIGNED NOT NULL,m365_group_id VARCHAR(128) NOT NULL,m365_display_name VARCHAR(255) NULL,m365_mail VARCHAR(255) NULL,created_date DATETIME NOT NULL,modified_date DATETIME NOT NULL,PRIMARY KEY (id),UNIQUE KEY domain_group (domain_id,civicrm_group_id),UNIQUE KEY m365_group_id (m365_group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_run (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,domain_id INT UNSIGNED NOT NULL,run_id CHAR(36) NOT NULL,operation_id CHAR(36) NULL,civicrm_group_id INT UNSIGNED NOT NULL,m365_group_id VARCHAR(128) NOT NULL,mode VARCHAR(16) NOT NULL,source VARCHAR(16) NOT NULL DEFAULT 'manual',status VARCHAR(32) NOT NULL,phase VARCHAR(16) NOT NULL DEFAULT 'complete',total_items INT UNSIGNED NOT NULL DEFAULT 0,processed_items INT UNSIGNED NOT NULL DEFAULT 0,error_items INT UNSIGNED NOT NULL DEFAULT 0,retry_count INT UNSIGNED NOT NULL DEFAULT 0,cancel_requested TINYINT(1) NOT NULL DEFAULT 0,started_date DATETIME NOT NULL,heartbeat_date DATETIME NULL,next_retry_date DATETIME NULL,completed_date DATETIME NULL,summary LONGTEXT NULL,PRIMARY KEY (id),UNIQUE KEY run_id (run_id),KEY domain_operation (domain_id,operation_id),KEY domain_group_date (domain_id,civicrm_group_id,started_date),KEY domain_worker (domain_id,status,next_retry_date,heartbeat_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_work (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,domain_id INT UNSIGNED NOT NULL,run_id CHAR(36) NOT NULL,item_type VARCHAR(16) NOT NULL,phase VARCHAR(16) NOT NULL,state VARCHAR(16) NOT NULL,civicrm_contact_id INT UNSIGNED NULL,effective_email VARCHAR(255) NULL,m365_user_id VARCHAR(128) NULL,attempts INT UNSIGNED NOT NULL DEFAULT 0,available_date DATETIME NULL,message TEXT NULL,created_date DATETIME NOT NULL,modified_date DATETIME NOT NULL,PRIMARY KEY (id),KEY domain_run_phase_state (domain_id,run_id,phase,state,available_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS civicrm_m365_sync_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,domain_id INT UNSIGNED NOT NULL,run_id CHAR(36) NOT NULL,civicrm_group_id INT UNSIGNED NOT NULL,civicrm_contact_id INT UNSIGNED NULL,effective_email VARCHAR(255) NULL,action VARCHAR(32) NOT NULL,result VARCHAR(16) NOT NULL,message TEXT NULL,m365_group_id VARCHAR(128) NULL,m365_user_id VARCHAR(128) NULL,created_date DATETIME NOT NULL,PRIMARY KEY (id),KEY domain_run_group (domain_id,run_id,civicrm_group_id),KEY domain_created (domain_id,created_date),KEY contact_email (civicrm_contact_id,effective_email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  private static function addMappingId(): void { if (!self::columnExists('civicrm_m365_group_mapping', 'id')) CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_m365_group_mapping ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST, DROP PRIMARY KEY, ADD PRIMARY KEY (id)'); }
  private static function addDomainIndexes(): void {
    if (!self::indexExists('civicrm_m365_group_mapping', 'domain_group')) CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_m365_group_mapping ADD UNIQUE KEY domain_group (domain_id,civicrm_group_id)');
    foreach ([['civicrm_m365_sync_run', 'domain_operation', 'domain_id,operation_id'],['civicrm_m365_sync_run', 'domain_group_date', 'domain_id,civicrm_group_id,started_date'],['civicrm_m365_sync_run', 'domain_worker', 'domain_id,status,next_retry_date,heartbeat_date'],['civicrm_m365_sync_work', 'domain_run_phase_state', 'domain_id,run_id,phase,state,available_date'],['civicrm_m365_sync_log', 'domain_run_group', 'domain_id,run_id,civicrm_group_id'],['civicrm_m365_sync_log', 'domain_created', 'domain_id,created_date']] as [$table, $index, $columns]) if (!self::indexExists($table, $index)) CRM_Core_DAO::executeQuery("ALTER TABLE `$table` ADD KEY `$index` ($columns)");
  }
  private static function migrateLegacyJob(int $domainId): void { if (!(int) CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_job WHERE domain_id=%1 AND api_entity=%2 AND api_action=%3 LIMIT 1', [1 => [$domainId, 'Positive'], 2 => ['M365GroupSync', 'String'], 3 => ['scheduled', 'String']])) { $legacy=(int) CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_job WHERE api_entity=%1 AND api_action=%2 ORDER BY id LIMIT 1', [1 => ['M365GroupSync', 'String'], 2 => ['scheduled', 'String']]); if ($legacy) CRM_Core_DAO::executeQuery('UPDATE civicrm_job SET domain_id=%1 WHERE id=%2', [1 => [$domainId, 'Positive'], 2 => [$legacy, 'Positive']]); } }
  private static function columnExists(string $table, string $column): bool { return (bool) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%1 AND column_name=%2', [1 => [$table, 'String'], 2 => [$column, 'String']]); }
  private static function indexExists(string $table, string $index): bool { return (bool) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=%1 AND table_name=%2', [1 => [$index, 'String'], 2 => [$table, 'String']]); }
}
