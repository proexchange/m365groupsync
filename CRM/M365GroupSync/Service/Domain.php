<?php

/** Centralizes the current CiviCRM domain used by extension-owned records. */
class CRM_M365GroupSync_Service_Domain {

  public static function id(): int {
    return (int) CRM_Core_Config::domainID();
  }

  public static function lockSuffix(): string {
    return '.domain.' . self::id();
  }

  /**
   * Legacy multidomain data cannot be assigned safely without an explicit
   * administrator confirmation. Operations are deliberately blocked meanwhile.
   */
  public static function isLegacyResolutionRequired(): bool {
    if (Civi::settings()->get('m365_group_sync_legacy_domain_resolution_required')) {
      return TRUE;
    }
    // The flag is domain-scoped, but unresolved legacy rows are database-wide.
    // Do not permit another site in the same database to synchronize first.
    try {
      foreach (['civicrm_m365_group_mapping', 'civicrm_m365_sync_run', 'civicrm_m365_sync_log', 'civicrm_m365_sync_work'] as $table) {
        if ((int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM `$table` WHERE domain_id IS NULL")) {
          return TRUE;
        }
      }
    }
    catch (Throwable $e) {
      // Pre-1.4 tables do not yet have domain_id; the upgrader runs before
      // operational services on those sites.
    }
    return FALSE;
  }

  public static function assertResolved(): void {
    if (self::isLegacyResolutionRequired()) {
      throw new CRM_Core_Exception(ts('Microsoft 365 synchronization is blocked until this domain confirms ownership of legacy sync data.'));
    }
  }

}
