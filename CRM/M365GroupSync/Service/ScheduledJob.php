<?php

/** Shared scheduled-job logic used by the APIv4 scheduled action. */
class CRM_M365GroupSync_Service_ScheduledJob {

  public function run(): array {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    CRM_M365GroupSync_Upgrader::ensureScheduledJob(CRM_M365GroupSync_Service_Domain::id());
    $now = time();
    $lastCleanup = (int) Civi::settings()->get('m365_group_sync_last_cleanup');
    $cleaned = 0;
    if (!$lastCleanup || $now - $lastCleanup >= 86400) {
      $cleaned = CRM_M365GroupSync_Service_Sync::cleanupLogs();
      Civi::settings()->set('m365_group_sync_last_cleanup', $now);
    }

    $queued = 0;
    $lastAuto = (int) Civi::settings()->get('m365_group_sync_last_auto_enqueue');
    $cadence = (string) (Civi::settings()->get('m365_group_sync_automatic_cadence') ?: 'Hourly');
    $intervals = ['Hourly' => 3600, 'Daily' => 86400, 'Weekly' => 604800, 'Monthly' => 2592000];
    $interval = $intervals[$cadence] ?? $intervals['Hourly'];
    if (Civi::settings()->get('m365_group_sync_enabled') && (!$lastAuto || $now - $lastAuto >= $interval)) {
      $operation = (new CRM_M365GroupSync_Service_Sync())->startMany(
        CRM_M365GroupSync_Service_Mapping::all(),
        'sync',
        'scheduled'
      );
      foreach ($operation['runs'] as $run) {
        $queued += empty($run['already_active']) && empty($run['skipped']) ? 1 : 0;
      }
      Civi::settings()->set('m365_group_sync_last_auto_enqueue', $now);
    }

    try {
      $worked = (new CRM_M365GroupSync_Service_Sync())->work(45);
    }
    catch (Throwable $e) {
      throw new CRM_Core_Exception('Microsoft 365 queue worker failed: ' . $e->getMessage(), 0, [], $e);
    }

    return ['domain_id' => CRM_M365GroupSync_Service_Domain::id(), 'automatic_cadence' => $cadence, 'automatic_runs_queued' => $queued, 'logs_cleaned' => $cleaned] + $worked;
  }

}
