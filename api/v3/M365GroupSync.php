<?php

function civicrm_api3_m365_group_sync_run($params) {
  m365groupsync_api_require_admin();
  $mode = $params['mode'] ?? 'compare';
  if (!in_array($mode, ['compare', 'dry_run', 'sync'], TRUE)) return civicrm_api3_create_error('Invalid mode.');
  $groupId = (int) ($params['group_id'] ?? 0);
  $m365 = CRM_M365GroupSync_Service_Mapping::getM365Id($groupId);
  if (!$m365) return civicrm_api3_create_error('CiviCRM Group is not mapped.');
  return civicrm_api3_create_success((new CRM_M365GroupSync_Service_Sync())->run($groupId, $m365, $mode), $params, 'M365GroupSync');
}

function civicrm_api3_m365_group_sync_run_spec(&$spec) {
  $spec['group_id'] = ['title' => 'CiviCRM Group', 'type' => CRM_Utils_Type::T_INT, 'api.required' => 1];
  $spec['mode'] = ['title' => 'Mode', 'type' => CRM_Utils_Type::T_STRING, 'api.default' => 'compare'];
}

/** Start one group or, when group_id is omitted, every mapped group. */
function civicrm_api3_m365_group_sync_start($params) {
  m365groupsync_api_require_admin();
  $mode = $params['mode'] ?? 'dry_run';
  if (!in_array($mode, ['dry_run', 'sync'], TRUE)) return civicrm_api3_create_error('Only dry_run and sync can be queued.');
  $mappings = CRM_M365GroupSync_Service_Mapping::all();
  if (!empty($params['group_id'])) {
    $groupId = (int) $params['group_id'];
    $mappings = array_values(array_filter($mappings, static fn(array $m): bool => (int) $m['civicrm_group_id'] === $groupId));
  }
  if (!$mappings) return civicrm_api3_create_error('No mapped groups were found.');
  $result = (new CRM_M365GroupSync_Service_Sync())->startMany($mappings, $mode, 'manual');
  return civicrm_api3_create_success($result, $params, 'M365GroupSync');
}

function civicrm_api3_m365_group_sync_start_spec(&$spec) {
  $spec['group_id'] = ['title' => 'CiviCRM Group', 'type' => CRM_Utils_Type::T_INT, 'api.required' => 0];
  $spec['mode'] = ['title' => 'Mode', 'type' => CRM_Utils_Type::T_STRING, 'api.required' => 1];
}

function civicrm_api3_m365_group_sync_process($params) {
  m365groupsync_api_require_admin();
  $result = (new CRM_M365GroupSync_Service_Sync())->processOperation((string) $params['operation_id']);
  return civicrm_api3_create_success($result, $params, 'M365GroupSync');
}
function civicrm_api3_m365_group_sync_process_spec(&$spec) { m365groupsync_operation_spec($spec); }

function civicrm_api3_m365_group_sync_status($params) {
  m365groupsync_api_require_admin();
  $result = (new CRM_M365GroupSync_Service_Sync())->operationStatus((string) $params['operation_id']);
  return civicrm_api3_create_success($result, $params, 'M365GroupSync');
}
function civicrm_api3_m365_group_sync_status_spec(&$spec) { m365groupsync_operation_spec($spec); }

function civicrm_api3_m365_group_sync_cancel($params) {
  m365groupsync_api_require_admin();
  $result = (new CRM_M365GroupSync_Service_Sync())->cancelOperation((string) $params['operation_id']);
  return civicrm_api3_create_success($result, $params, 'M365GroupSync');
}
function civicrm_api3_m365_group_sync_cancel_spec(&$spec) { m365groupsync_operation_spec($spec); }

/** Queue hourly automatic runs, then advance all queued manual/automatic work. */
function civicrm_api3_m365_group_sync_scheduled($params) {
  $now = time();
  $lastCleanup = (int) Civi::settings()->get('m365_group_sync_last_cleanup');
  $cleaned = 0;
  if (!$lastCleanup || $now - $lastCleanup >= 86400) {
    $cleaned = CRM_M365GroupSync_Service_Sync::cleanupLogs();
    Civi::settings()->set('m365_group_sync_last_cleanup', $now);
  }
  $queued = 0;
  $lastAuto = (int) Civi::settings()->get('m365_group_sync_last_auto_enqueue');
  if (Civi::settings()->get('m365_group_sync_enabled') && (!$lastAuto || $now - $lastAuto >= 3600)) {
    $result = (new CRM_M365GroupSync_Service_Sync())->startMany(CRM_M365GroupSync_Service_Mapping::all(), 'sync', 'scheduled');
    foreach ($result['runs'] as $run) $queued += empty($run['already_active']) && empty($run['skipped']) ? 1 : 0;
    Civi::settings()->set('m365_group_sync_last_auto_enqueue', $now);
  }
  try {
    $worked = (new CRM_M365GroupSync_Service_Sync())->work(45);
  }
  catch (Throwable $e) {
    return civicrm_api3_create_error('Microsoft 365 queue worker failed: ' . $e->getMessage());
  }
  return civicrm_api3_create_success(['automatic_runs_queued' => $queued, 'logs_cleaned' => $cleaned] + $worked, $params, 'M365GroupSync');
}

function m365groupsync_operation_spec(array &$spec): void {
  $spec['operation_id'] = ['title' => 'Operation ID', 'type' => CRM_Utils_Type::T_STRING, 'api.required' => 1];
}

function m365groupsync_api_require_admin(): void {
  if (!CRM_Core_Permission::check(M365GROUPSYNC_ADMIN_PERMISSION)) throw new CRM_Core_Exception('Permission denied.');
}
