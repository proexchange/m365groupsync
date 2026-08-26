<?php

class CRM_M365GroupSync_Service_Mapping {
  public static function all(): array {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.*, g.title AS civicrm_group_title,
              CASE WHEN g.saved_search_id IS NOT NULL OR g.children IS NOT NULL THEN 'Smart' ELSE 'Static' END AS group_type,
              r.status AS last_status, r.completed_date AS last_sync, r.summary AS last_summary
     FROM civicrm_m365_group_mapping m
         JOIN civicrm_group g ON g.id = m.civicrm_group_id
    LEFT JOIN civicrm_m365_sync_run r ON r.id = (
              SELECT r2.id FROM civicrm_m365_sync_run r2
               WHERE r2.domain_id = m.domain_id AND r2.civicrm_group_id = m.civicrm_group_id
            ORDER BY r2.started_date DESC, r2.id DESC LIMIT 1)
        WHERE m.domain_id = %1
     ORDER BY g.title"
      , [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive']]
    );
    $out = [];
    while ($dao->fetch()) {
      $summary = json_decode((string) $dao->last_summary, TRUE) ?: [];
      $out[] = [
        'civicrm_group_id' => (int) $dao->civicrm_group_id,
        'civicrm_group_title' => $dao->civicrm_group_title,
        'group_type' => $dao->group_type,
        'm365_group_id' => $dao->m365_group_id,
        'm365_display_name' => $dao->m365_display_name,
        'm365_mail' => $dao->m365_mail,
        'last_status' => $dao->last_status,
        'last_sync' => $dao->last_sync,
        'summary' => $summary,
      ];
    }
    return $out;
  }

  public static function getM365Id(int $groupId): string {
    return (string) CRM_Core_DAO::singleValueQuery('SELECT m365_group_id FROM civicrm_m365_group_mapping WHERE domain_id=%1 AND civicrm_group_id=%2', [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive'], 2 => [$groupId, 'Positive']]);
  }

  public static function unmappedGroups(): array {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT g.id, g.title, CASE WHEN g.saved_search_id IS NOT NULL OR g.children IS NOT NULL THEN 'Smart' ELSE 'Static' END AS group_type
         FROM civicrm_group g
    LEFT JOIN civicrm_m365_group_mapping m ON m.civicrm_group_id = g.id AND m.domain_id=%1
        WHERE g.is_active = 1 AND m.civicrm_group_id IS NULL
     ORDER BY g.title"
      , [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive']]
    );
    $out = [];
    while ($dao->fetch()) {
      $out[] = ['civicrm_group_id' => (int) $dao->id, 'civicrm_group_title' => $dao->title, 'group_type' => $dao->group_type];
    }
    return $out;
  }

  public static function isMappedElsewhere(string $m365Id, int $groupId): bool {
    return (bool) CRM_Core_DAO::singleValueQuery(
      'SELECT civicrm_group_id FROM civicrm_m365_group_mapping WHERE m365_group_id = %1 AND NOT (domain_id=%2 AND civicrm_group_id=%3)',
      [1 => [$m365Id, 'String'], 2 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive'], 3 => [$groupId, 'Positive']]
    );
  }

  public static function save(int $groupId, string $m365Id): void {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    if (!CRM_Core_Permission::check(M365GROUPSYNC_ADMIN_PERMISSION)) {
      throw new CRM_Core_Exception(ts('Permission denied.'));
    }
    if ($m365Id !== '' && self::isMappedElsewhere($m365Id, $groupId)) {
      $other = CRM_Core_DAO::singleValueQuery(
        'SELECT g.title FROM civicrm_m365_group_mapping m JOIN civicrm_group g ON g.id=m.civicrm_group_id WHERE m.m365_group_id=%1',
        [1 => [$m365Id, 'String']]
      );
      throw new CRM_Core_Exception(ts('This Microsoft 365 Group is already mapped to CiviCRM Group “%1”.', [1 => $other]));
    }
    $currentId = self::getM365Id($groupId);
    if ($currentId === $m365Id) {
      return;
    }
    $connectionLock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 5);
    if (!$connectionLock->isAcquired()) {
      throw new CRM_Core_Exception(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then save the mapping again.'));
    }
    try {
      $group = $m365Id === '' ? NULL : self::fetchAndValidateMicrosoftGroup($m365Id);
      CRM_M365GroupSync_Service_Sync::requestCancellationForGroup($groupId);
      $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Sync::groupLockName($groupId), 0);
      if (!$lock->isAcquired()) {
        throw new CRM_Core_Exception(ts('A synchronization batch is currently running. Its cancellation was requested; wait for that batch to finish, then save the mapping again.'));
      }
      try {
        if ($m365Id === '') {
          CRM_Core_DAO::executeQuery('DELETE FROM civicrm_m365_group_mapping WHERE domain_id=%1 AND civicrm_group_id=%2', [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive'], 2 => [$groupId, 'Positive']]);
          return;
        }
        CRM_Core_DAO::executeQuery(
          'INSERT INTO civicrm_m365_group_mapping (domain_id,civicrm_group_id,m365_group_id,m365_display_name,m365_mail,created_date,modified_date)
           VALUES (%1,%2,%3,%4,%5,NOW(),NOW())
           ON DUPLICATE KEY UPDATE m365_group_id=VALUES(m365_group_id),m365_display_name=VALUES(m365_display_name),m365_mail=VALUES(m365_mail),modified_date=NOW()',
          [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive'], 2 => [$groupId, 'Positive'], 3 => [$m365Id, 'String'], 4 => [$group['displayName'], 'String'], 5 => [$group['mail'], 'String']]
        );
      }
      finally {
        $lock->release();
      }
    }
    finally {
      $connectionLock->release();
    }
  }

  /** Fetch and verify that a posted object is a supported mail-enabled M365 Group. */
  public static function validateMicrosoftGroup(string $m365Id): array {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 5);
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then validate the group again.'));
    }
    try {
      return self::fetchAndValidateMicrosoftGroup($m365Id);
    }
    finally {
      $lock->release();
    }
  }

  private static function fetchAndValidateMicrosoftGroup(string $m365Id): array {
    $group = (new CRM_M365GroupSync_Service_Graph())->group($m365Id);
    if (empty($group['id']) || strcasecmp((string) $group['id'], $m365Id) !== 0) {
      throw new CRM_Core_Exception(ts('Microsoft Graph did not return the requested group.'));
    }
    if (!in_array('Unified', (array) ($group['groupTypes'] ?? []), TRUE)) {
      throw new CRM_Core_Exception(ts('Only Microsoft 365 (Unified) groups can be synchronized.'));
    }
    if (empty($group['mailEnabled']) || trim((string) ($group['mail'] ?? '')) === '') {
      throw new CRM_Core_Exception(ts('The selected Microsoft 365 Group must be mail enabled and have an email address.'));
    }
    return $group;
  }

  public static function deleteForGroup(int $groupId): void {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    CRM_M365GroupSync_Service_Sync::requestCancellationForGroup($groupId);
    $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Sync::groupLockName($groupId), 0);
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception(ts('A synchronization batch is currently running. Its cancellation was requested; wait for that batch to finish before deleting this group.'));
    }
    try {
      CRM_Core_DAO::executeQuery('DELETE FROM civicrm_m365_group_mapping WHERE domain_id=%1 AND civicrm_group_id=%2', [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive'], 2 => [$groupId, 'Positive']]);
    }
    finally {
      $lock->release();
    }
  }
}
