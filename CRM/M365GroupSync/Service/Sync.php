<?php

/** Durable, resumable Microsoft 365 membership reconciliation. */
class CRM_M365GroupSync_Service_Sync {
  private const ACTIVE = "'queued','running','retry_wait'";
  private const BATCH = 20;
  private const MAX_ATTEMPTS = 5;
  private ?CRM_M365GroupSync_Service_Graph $graph = NULL;

  public static function groupLockName(int $groupId): string {
    return 'data.m365groupsync' . CRM_M365GroupSync_Service_Domain::lockSuffix() . '.group.' . $groupId;
  }

  public static function requestCancellationForGroup(int $groupId): int {
    $dao = CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_m365_sync_run SET cancel_requested=1,heartbeat_date=NOW() WHERE domain_id=%1 AND civicrm_group_id=%2 AND status IN (' . self::ACTIVE . ')',
      [1 => [self::domainId(), 'Positive'], 2 => [$groupId, 'Positive']]
    );
    return (int) $dao->affectedRows();
  }

  public static function requestCancellationForAll(bool $allDomains = FALSE): int {
    $where = $allDomains ? '' : ' WHERE domain_id=%1';
    $dao = CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_m365_sync_run SET cancel_requested=1,heartbeat_date=NOW()' . ($allDomains ? ' WHERE' : ' WHERE domain_id=%1 AND') . ' status IN (' . self::ACTIVE . ')',
      $allDomains ? [] : [1 => [self::domainId(), 'Positive']]
    );
    return (int) $dao->affectedRows();
  }

  /** Compare completes immediately; Dry Run and Sync return a queued run. */
  public function run(int $groupId, string $m365GroupId, string $mode = 'compare'): array {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    if ($mode === 'compare') {
      return $this->compare($groupId, $m365GroupId);
    }
    return $this->start($groupId, $m365GroupId, $mode);
  }

  public function compare(int $groupId, string $m365GroupId): array {
    $run = $this->createRun($groupId, $m365GroupId, 'compare', 'manual', $this->randomId(), 'running', 'snapshot');
    try {
      $data = $this->snapshot($run);
      $summary = $data['summary'];
      $status = ($summary['missing'] || $summary['extra']) ? 'differences_found' : 'success';
      $this->finish($run['run_id'], $summary, $status);
      return $summary + ['status' => $status, 'run_id' => $run['run_id'], 'operation_id' => $run['operation_id']];
    }
    catch (Throwable $e) {
      $this->failRun($run['run_id'], $e);
      throw $e;
    }
  }

  public function start(int $groupId, string $m365GroupId, string $mode, string $source = 'manual', ?string $operationId = NULL): array {
    CRM_M365GroupSync_Service_Domain::assertResolved();
    if (!in_array($mode, ['dry_run', 'sync'], TRUE)) {
      throw new CRM_Core_Exception(ts('Only Dry Run and Sync can be queued.'));
    }
    $lock = Civi::lockManager()->acquire(self::groupLockName($groupId), 0);
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception(ts('This group is already being prepared or synchronized.'));
    }
    try {
      $active = $this->activeRun($groupId);
      if ($active) {
        return $active + ['already_active' => TRUE];
      }
      return $this->createRun($groupId, $m365GroupId, $mode, $source, $operationId ?: $this->randomId(), 'queued', 'snapshot') + ['already_active' => FALSE];
    }
    finally {
      $lock->release();
    }
  }

  public function startMany(array $mappings, string $mode, string $source = 'manual'): array {
    $operation = $this->randomId();
    $runs = [];
    foreach ($mappings as $mapping) {
      try {
        $runs[] = $this->start((int) $mapping['civicrm_group_id'], (string) $mapping['m365_group_id'], $mode, $source, $operation);
      }
      catch (Throwable $e) {
        if ($source === 'manual' && count($mappings) === 1) throw $e;
        $runs[] = ['group_id' => (int) $mapping['civicrm_group_id'], 'status' => 'skipped', 'skipped' => TRUE, 'error' => $e->getMessage()];
      }
    }
    if (count($runs) === 1 && !empty($runs[0]['already_active'])) {
      $operation = $runs[0]['operation_id'];
    }
    return ['operation_id' => $operation, 'status' => 'queued', 'runs' => $runs];
  }

  public function processOperation(string $operationId): array {
    $next = $this->nextRun($operationId);
    if ($next) {
      $this->processRun($next);
    }
    return $this->operationStatus($operationId);
  }

  public function work(int $seconds = 45): array {
    $until = microtime(TRUE) + max(1, $seconds);
    $count = 0;
    while (microtime(TRUE) < $until && ($runId = $this->nextRun(NULL))) {
      $this->processRun($runId);
      $count++;
    }
    return ['batches_processed' => $count, 'active_runs' => (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_m365_sync_run WHERE domain_id=%1 AND status IN (' . self::ACTIVE . ')', [1 => [self::domainId(), 'Positive']])];
  }

  public function cancelOperation(string $operationId): array {
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET cancel_requested=1,heartbeat_date=NOW() WHERE domain_id=%1 AND operation_id=%2 AND status IN (' . self::ACTIVE . ')', [1 => [self::domainId(), 'Positive'], 2 => [$operationId, 'String']]);
    return $this->operationStatus($operationId);
  }

  public function operationStatus(string $operationId): array {
    $dao = CRM_Core_DAO::executeQuery('SELECT r.*,g.title group_title FROM civicrm_m365_sync_run r LEFT JOIN civicrm_group g ON g.id=r.civicrm_group_id WHERE r.domain_id=%1 AND operation_id=%2 ORDER BY r.id', [1 => [self::domainId(), 'Positive'], 2 => [$operationId, 'String']]);
    $runs = []; $active = 0; $errors = 0;
    while ($dao->fetch()) {
      $isActive = in_array($dao->status, ['queued', 'running', 'retry_wait'], TRUE);
      $active += $isActive ? 1 : 0;
      $errors += (int) $dao->error_items;
      $runs[] = [
        'run_id' => $dao->run_id, 'operation_id' => $dao->operation_id,
        'group_id' => (int) $dao->civicrm_group_id, 'group' => $dao->group_title ?: '#' . $dao->civicrm_group_id,
        'mode' => $dao->mode, 'source' => $dao->source, 'status' => $dao->status, 'phase' => $dao->phase,
        'total' => (int) $dao->total_items, 'processed' => (int) $dao->processed_items,
        'errors' => (int) $dao->error_items, 'next_retry' => $dao->next_retry_date,
        'retries' => (int) $dao->retry_count,
        'cancel_requested' => (bool) $dao->cancel_requested,
        'summary' => json_decode((string) $dao->summary, TRUE) ?: [],
      ];
    }
    return ['operation_id' => $operationId, 'status' => !$runs ? 'not_found' : ($active ? 'running' : ($errors ? 'completed_with_errors' : 'complete')), 'active' => $active, 'errors' => $errors, 'runs' => $runs];
  }

  private function processRun(string $runId): void {
    $run = $this->getRun($runId);
    if (!$run) return;
    $lock = Civi::lockManager()->acquire(self::groupLockName($run['civicrm_group_id']), 0);
    if (!$lock->isAcquired()) return;
    $connectionLock = NULL;
    try {
      $connectionLock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 0);
      if (!$connectionLock->isAcquired()) return;
      $run = $this->getRun($runId);
      if (!$run || !in_array($run['status'], ['queued', 'running', 'retry_wait'], TRUE)) return;
      if ($run['cancel_requested']) { $this->cancelRun($runId); return; }
      if (!hash_equals((string) $run['m365_group_id'], CRM_M365GroupSync_Service_Mapping::getM365Id($run['civicrm_group_id']))) {
        $this->cancelRun($runId);
        return;
      }
      $this->heartbeat($runId, 'running');
      if ($run['phase'] === 'snapshot') { $this->prepare($run); return; }
      $this->advance($runId);
      $run = $this->getRun($runId);
      if (!$run || !in_array($run['status'], ['queued', 'running', 'retry_wait'], TRUE)) return;
      if ($run['cancel_requested']) { $this->cancelRun($runId); return; }
      if (in_array($run['phase'], ['resolve', 'invite', 'add', 'remove'], TRUE)) {
        $this->processBatch($run);
        $this->advance($runId);
      }
    }
    catch (Throwable $e) {
      if ($run['phase'] === 'snapshot' && $this->deferSnapshot($run, $e)) return;
      $this->failRun($runId, $e);
      Civi::log()->error('M365 Group Sync worker failed: {error}', ['error' => $e->getMessage()]);
    }
    finally {
      if ($connectionLock && $connectionLock->isAcquired()) $connectionLock->release();
      $lock->release();
    }
  }

  private function prepare(array $run): void {
    $data = $this->snapshot($run); $summary = $data['summary'];
    $work = [];
    foreach ($data['missing'] as $email => $contacts) {
      $work[] = [$run['run_id'], 'missing', 'resolve', (int) ($contacts[0] ?? 0), $email, ''];
    }
    if ($run['mode'] === 'sync') {
      foreach ($data['extras'] as $member) {
        $work[] = [$run['run_id'], 'extra', 'remove', 0, $this->identityEmails($member)[0] ?? '', (string) $member['id']];
      }
    }
    $this->insertWorkMany($work);
    $total = count($data['missing']) + count($data['extras']);
    $processed = $run['mode'] === 'dry_run' ? count($data['extras']) : 0;
    $phase = $data['missing'] ? 'resolve' : ($run['mode'] === 'sync' && $data['extras'] ? 'remove' : 'complete');
    CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET status=%1,phase=%2,total_items=%3,processed_items=%4,retry_count=0,summary=%5,heartbeat_date=NOW() WHERE domain_id=%6 AND run_id=%7', [
      1 => [$phase === 'complete' ? (($summary['missing'] || $summary['extra']) ? 'differences_found' : 'success') : 'running', 'String'],
      2 => [$phase, 'String'], 3 => [$total, 'Integer'], 4 => [$processed, 'Integer'], 5 => [json_encode($summary), 'String'], 6 => [self::domainId(), 'Positive'], 7 => [$run['run_id'], 'String'],
    ]);
    if ($phase === 'complete') $this->finish($run['run_id'], $summary, ($summary['missing'] || $summary['extra']) ? 'differences_found' : 'success');
  }

  private function processBatch(array $run): void {
    $items = $this->dueItems($run['run_id'], $run['phase']);
    if (!$items) { $this->waitForRetry($run); return; }
    $summary = $run['summary']; $logs = [];
    try { $responses = $this->graphBatch($run, $items); }
    catch (Throwable $e) {
      $retryable = !($e instanceof CRM_M365GroupSync_GraphException) || $e->httpStatus === 0 || in_array($e->httpStatus, [429, 503, 504], TRUE);
      foreach ($items as $item) {
        if ($retryable && $item['attempts'] + 1 < self::MAX_ATTEMPTS) $this->retryOrFail($run, $item, $e->getMessage(), $e instanceof CRM_M365GroupSync_GraphException ? $e->retryAfter : NULL);
        else $this->failItem($run, $item, $e->getMessage(), $run['phase'] === 'remove' ? 'member_remove_failed' : 'member_add_failed', $logs, $summary);
      }
      $this->saveSummary($run['run_id'], $summary); $this->logMany($logs); $this->waitForRetry($run); return;
    }
    foreach ($items as $item) {
      $res = $responses[$item['id']] ?? ['status' => 0, 'headers' => [], 'body' => ['error' => ['message' => 'Missing batch response']]];
      $code = (int) $res['status'];
      if ($code === 0 || in_array($code, [429, 503, 504], TRUE) || ($run['phase'] === 'add' && $code === 404)) {
        if ($item['attempts'] + 1 >= self::MAX_ATTEMPTS) $this->failItem($run, $item, $this->message($res), $run['phase'] === 'remove' ? 'member_remove_failed' : 'member_add_failed', $logs, $summary);
        else $this->retryOrFail($run, $item, $this->message($res), $this->retryAfter($res));
        continue;
      }
      if ($run['phase'] === 'resolve' && $code >= 200 && $code < 300) {
        $matches = array_values((array) ($res['body']['value'] ?? []));
        if (count($matches) > 1) $this->failItem($run, $item, ts('Multiple Microsoft users match this email; resolve the duplicate identities before syncing.'), 'member_add_failed', $logs, $summary);
        elseif ($run['mode'] === 'dry_run') { if (!$matches) $summary['would_create_guests']++; $this->done($run['run_id'], $item['id']); }
        elseif ($matches && !empty($matches[0]['id'])) $this->move($item['id'], 'add', (string) $matches[0]['id']);
        else { $summary['would_create_guests']++; $this->move($item['id'], 'invite'); }
        continue;
      }
      if ($run['phase'] === 'invite' && $code >= 200 && $code < 300) {
        $id = (string) ($res['body']['invitedUser']['id'] ?? '');
        if (!$id) $this->failItem($run, $item, ts('Invitation did not return a guest identity.'), 'member_add_failed', $logs, $summary);
        else { $this->move($item['id'], 'add', $id); $logs[] = $this->logRow($run, $item, 'guest_created', 'success', 'Guest created without invitation email', $id); }
        continue;
      }
      if ($run['phase'] === 'add' && (($code >= 200 && $code < 300) || $this->alreadyMember($res))) {
        $this->done($run['run_id'], $item['id']);
        if ($code < 300) { $summary['added']++; $logs[] = $this->logRow($run, $item, 'member_added', 'success', 'Member added', $item['m365_user_id']); }
        else $logs[] = $this->logRow($run, $item, 'member_present', 'success', 'Member was already present', $item['m365_user_id']);
        continue;
      }
      if ($run['phase'] === 'remove' && (($code >= 200 && $code < 300) || $code === 404)) {
        $this->done($run['run_id'], $item['id']);
        if ($code !== 404) { $summary['removed']++; $logs[] = $this->logRow($run, $item, 'member_removed', 'success', 'Non-owner member removed', $item['m365_user_id']); }
        else $logs[] = $this->logRow($run, $item, 'member_absent', 'success', 'Member was already absent', $item['m365_user_id']);
        continue;
      }
      $this->failItem($run, $item, $this->message($res), $run['phase'] === 'remove' ? 'member_remove_failed' : 'member_add_failed', $logs, $summary);
    }
    $this->saveSummary($run['run_id'], $summary); $this->logMany($logs); $this->heartbeat($run['run_id'], 'running');
  }

  private function graphBatch(array $run, array $items): array {
    $values = [];
    foreach ($items as $item) $values[$item['id']] = in_array($run['phase'], ['resolve', 'invite'], TRUE) ? $item['effective_email'] : $item['m365_user_id'];
    return match ($run['phase']) {
      'resolve' => $this->client()->userLookupBatch($values), 'invite' => $this->client()->invitationBatch($values),
      'add' => $this->client()->addMemberBatch($run['m365_group_id'], $values), 'remove' => $this->client()->removeMemberBatch($run['m365_group_id'], $values),
      default => throw new CRM_Core_Exception(ts('Invalid synchronization phase.')),
    };
  }

  private function advance(string $runId): void {
    for ($i = 0; $i < 5; $i++) {
      $run = $this->getRun($runId); if (!$run || !in_array($run['status'], ['queued', 'running', 'retry_wait'], TRUE)) return;
      $pending = (int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_m365_sync_work WHERE domain_id=%1 AND run_id=%2 AND phase=%3 AND state IN ('pending','retry')", [1 => [self::domainId(), 'Positive'], 2 => [$runId, 'String'], 3 => [$run['phase'], 'String']]);
      if ($pending) { $this->waitForRetry($run); return; }
      $next = match ($run['phase']) { 'resolve' => $run['mode'] === 'dry_run' ? 'complete' : 'invite', 'invite' => 'add', 'add' => 'remove', default => 'complete' };
      if ($next === 'complete') {
        $status = $run['error_items'] ? 'completed_with_errors' : (($run['mode'] === 'dry_run' && ($run['summary']['missing'] || $run['summary']['extra'])) ? 'differences_found' : 'success');
        $this->finish($runId, $run['summary'], $status); return;
      }
      CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET phase=%1,status='running',next_retry_date=NULL,heartbeat_date=NOW() WHERE domain_id=%2 AND run_id=%3", [1 => [$next, 'String'], 2 => [self::domainId(), 'Positive'], 3 => [$runId, 'String']]);
    }
  }

  private function snapshot(array $run): array {
    $expected = $this->expected($run['civicrm_group_id'], $run['run_id']); $graph = $this->client();
    $graph->group($run['m365_group_id']); $members = $graph->members($run['m365_group_id']);
    $owners = array_fill_keys(array_column($graph->owners($run['m365_group_id']), 'id'), TRUE);
    $memberEmails = []; $emailIds = []; $ownerCount = 0;
    foreach ($members as $member) {
      $emails = $this->identityEmails($member);
      if (empty($member['id']) || !$emails) throw new CRM_Core_Exception(ts('Microsoft returned a group member without readable identity fields. Reconciliation stopped before writes.'));
      if (isset($owners[$member['id']])) $ownerCount++;
      foreach ($emails as $email) { $memberEmails[$email] = $member['id']; $emailIds[$email][$member['id']] = TRUE; }
    }
    foreach ($emailIds as $email => $ids) if (count($ids) > 1) throw new CRM_Core_Exception(ts('Multiple current Microsoft group members resolve to %1. Reconciliation stopped before writes.', [1 => $email]));
    $missing = [];
    foreach ($expected['emails'] as $email => $contacts) if (!isset($memberEmails[$email])) $missing[$email] = $contacts;
    $extras = [];
    foreach ($members as $member) if (!isset($owners[$member['id']]) && !$this->memberExpected($member, $expected['emails'])) $extras[] = $member;
    $summary = ['qualifying_contacts' => $expected['qualifying'], 'unique_emails' => count($expected['emails']), 'duplicate_email_count' => $expected['duplicates'], 'skipped_contacts' => $expected['skipped'], 'm365_managed_members' => count($members) - $ownerCount, 'owner_members_excluded' => $ownerCount, 'missing' => count($missing), 'extra' => count($extras), 'would_create_guests' => 0, 'would_add' => count($missing), 'would_remove' => count($extras), 'added' => 0, 'removed' => 0, 'errors' => 0];
    return compact('missing', 'extras', 'summary');
  }

  private function expected(int $groupId, string $runId): array {
    $smart = (bool) CRM_Core_DAO::singleValueQuery('SELECT (saved_search_id IS NOT NULL OR children IS NOT NULL) FROM civicrm_group WHERE id=%1 AND is_active=1', [1 => [$groupId, 'Positive']]);
    if ($smart) { CRM_Contact_BAO_GroupContactCache::invalidateGroupContactCache($groupId); CRM_Contact_BAO_GroupContactCache::loadAll([$groupId]); }
    $table = $smart ? 'civicrm_group_contact_cache' : 'civicrm_group_contact'; $status = $smart ? '' : " AND gc.status='Added'";
    $dao = CRM_Core_DAO::executeQuery("SELECT DISTINCT c.id FROM civicrm_contact c JOIN $table gc ON gc.contact_id=c.id WHERE gc.group_id=%1 AND c.is_deleted=0 AND c.is_deceased=0$status", [1 => [$groupId, 'Positive']]);
    $ids = []; while ($dao->fetch()) $ids[] = (int) $dao->id; $byContact = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
      $dao = CRM_Core_DAO::executeQuery('SELECT c.id contact_id,e.email,e.on_hold,e.is_bulkmail,e.is_primary FROM civicrm_contact c LEFT JOIN civicrm_email e ON e.contact_id=c.id WHERE c.id IN (' . implode(',', $chunk) . ') ORDER BY c.id,e.is_bulkmail DESC,e.is_primary DESC,e.id');
      while ($dao->fetch()) $byContact[(int) $dao->contact_id][] = ['email' => (string) $dao->email, 'on_hold' => (bool) $dao->on_hold, 'is_bulkmail' => (bool) $dao->is_bulkmail, 'is_primary' => (bool) $dao->is_primary];
    }
    $emails = []; $qualifying = 0; $skipped = 0; $logs = [];
    foreach ($ids as $id) {
      $email = $this->effectiveEmail($byContact[$id] ?? []);
      if (!$email) { $skipped++; $logs[] = [self::domainId(), $runId, $groupId, $id, '', 'contact_skipped', 'success', 'No valid, non-held bulk or primary email', '', '']; }
      else { $qualifying++; $emails[$email][] = $id; }
    }
    $duplicates = $qualifying - count($emails);
    foreach ($emails as $email => $contactIds) if (count($contactIds) > 1) $logs[] = [self::domainId(), $runId, $groupId, $contactIds[0], $email, 'duplicate_email', 'success', 'Duplicate effective email; one Microsoft membership will be managed', '', ''];
    $this->logMany($logs); return compact('emails', 'qualifying', 'skipped', 'duplicates');
  }

  private function effectiveEmail(array $rows): ?string {
    foreach ([TRUE, FALSE] as $bulk) foreach ($rows as $row) {
      if (($bulk && empty($row['is_bulkmail'])) || (!$bulk && empty($row['is_primary']))) continue;
      $email = strtolower(trim((string) $row['email'])); if (!$row['on_hold'] && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    }
    return NULL;
  }
  private function identityEmails(array $member): array {
    $values = [$member['mail'] ?? NULL, $member['userPrincipalName'] ?? NULL, ...((array) ($member['otherMails'] ?? []))]; $out = [];
    foreach ($values as $value) { $email = strtolower(trim((string) $value)); if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[$email] = $email; }
    return array_values($out);
  }
  private function memberExpected(array $member, array $emails): bool { foreach ($this->identityEmails($member) as $email) if (isset($emails[$email])) return TRUE; return FALSE; }

  private function createRun(int $groupId, string $m365Id, string $mode, string $source, string $operation, string $status, string $phase): array {
    $run = $this->randomId();
    CRM_Core_DAO::executeQuery('INSERT INTO civicrm_m365_sync_run (domain_id,run_id,operation_id,civicrm_group_id,m365_group_id,mode,source,status,phase,started_date,heartbeat_date) VALUES (%1,%2,%3,%4,%5,%6,%7,%8,%9,NOW(),NOW())', [1 => [self::domainId(), 'Positive'], 2 => [$run, 'String'], 3 => [$operation, 'String'], 4 => [$groupId, 'Positive'], 5 => [$m365Id, 'String'], 6 => [$mode, 'String'], 7 => [$source, 'String'], 8 => [$status, 'String'], 9 => [$phase, 'String']]);
    return ['run_id' => $run, 'operation_id' => $operation, 'group_id' => $groupId, 'civicrm_group_id' => $groupId, 'm365_group_id' => $m365Id, 'mode' => $mode, 'source' => $source, 'status' => $status, 'phase' => $phase];
  }
  private function getRun(string $id): ?array {
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_m365_sync_run WHERE domain_id=%1 AND run_id=%2 LIMIT 1', [1 => [self::domainId(), 'Positive'], 2 => [$id, 'String']]); if (!$dao->fetch()) return NULL;
    return ['run_id' => $dao->run_id, 'operation_id' => $dao->operation_id, 'civicrm_group_id' => (int) $dao->civicrm_group_id, 'm365_group_id' => $dao->m365_group_id, 'mode' => $dao->mode, 'source' => $dao->source, 'status' => $dao->status, 'phase' => $dao->phase, 'error_items' => (int) $dao->error_items, 'retry_count' => (int) $dao->retry_count, 'cancel_requested' => (bool) $dao->cancel_requested, 'summary' => json_decode((string) $dao->summary, TRUE) ?: []];
  }
  private function activeRun(int $groupId): ?array {
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_m365_sync_run WHERE domain_id=%1 AND civicrm_group_id=%2 AND status IN (' . self::ACTIVE . ') ORDER BY id DESC LIMIT 1', [1 => [self::domainId(), 'Positive'], 2 => [$groupId, 'Positive']]); if (!$dao->fetch()) return NULL;
    return ['run_id' => $dao->run_id, 'operation_id' => $dao->operation_id, 'group_id' => (int) $dao->civicrm_group_id, 'm365_group_id' => $dao->m365_group_id, 'mode' => $dao->mode, 'source' => $dao->source, 'status' => $dao->status, 'phase' => $dao->phase];
  }
  private function nextRun(?string $operation): ?string {
    $where = $operation ? 'AND operation_id=%2' : ''; $params = [1 => [self::domainId(), 'Positive']]; if ($operation) $params[2] = [$operation, 'String'];
    $dao = CRM_Core_DAO::executeQuery('SELECT run_id FROM civicrm_m365_sync_run WHERE domain_id=%1 AND status IN (' . self::ACTIVE . ") $where AND (next_retry_date IS NULL OR next_retry_date<=NOW()) ORDER BY heartbeat_date,id LIMIT 1", $params);
    return $dao->fetch() ? $dao->run_id : NULL;
  }
  private function dueItems(string $run, string $phase): array {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_m365_sync_work WHERE domain_id=%1 AND run_id=%2 AND phase=%3 AND state IN ('pending','retry') AND (available_date IS NULL OR available_date<=NOW()) ORDER BY id LIMIT " . self::BATCH, [1 => [self::domainId(), 'Positive'], 2 => [$run, 'String'], 3 => [$phase, 'String']]); $out = [];
    while ($dao->fetch()) $out[] = ['id' => (int) $dao->id, 'contact_id' => (int) $dao->civicrm_contact_id, 'effective_email' => $dao->effective_email, 'm365_user_id' => $dao->m365_user_id, 'attempts' => (int) $dao->attempts]; return $out;
  }
  private function insertWorkMany(array $rows): void {
    foreach (array_chunk($rows, 100) as $chunk) {
      $params=[];$values=[];$p=1;
      foreach($chunk as $row){array_unshift($row,self::domainId());$h=[];$types=['Integer','String','String','String','Integer','String','String'];foreach($row as $i=>$value){$params[$p]=[$value,$types[$i]];$h[]='%'.$p++;}$values[]=sprintf("(%s,%s,%s,%s,'pending',NULLIF(%s,0),NULLIF(%s,''),NULLIF(%s,''),0,NOW(),NOW())",...$h);}
      CRM_Core_DAO::executeQuery('INSERT INTO civicrm_m365_sync_work (domain_id,run_id,item_type,phase,state,civicrm_contact_id,effective_email,m365_user_id,attempts,created_date,modified_date) VALUES '.implode(',',$values),$params);
    }
  }
  private function move(int $id, string $phase, string $user = ''): void { CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET phase=%1,state='pending',m365_user_id=COALESCE(NULLIF(%2,''),m365_user_id),attempts=0,available_date=NULL,message=NULL,modified_date=NOW() WHERE domain_id=%3 AND id=%4", [1 => [$phase, 'String'], 2 => [$user, 'String'], 3 => [self::domainId(), 'Positive'], 4 => [$id, 'Integer']]); }
  private function done(string $run, int $id): void { CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET state='done',available_date=NULL,modified_date=NOW() WHERE domain_id=%1 AND id=%2", [1 => [self::domainId(), 'Positive'], 2 => [$id, 'Integer']]); CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET processed_items=processed_items+1,heartbeat_date=NOW() WHERE domain_id=%1 AND run_id=%2', [1 => [self::domainId(), 'Positive'], 2 => [$run, 'String']]); }
  private function failItem(array $run, array $item, string $message, string $action, array &$logs, array &$summary): void { CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET state='error',message=%1,modified_date=NOW() WHERE domain_id=%2 AND id=%3", [1 => [$message, 'String'], 2 => [self::domainId(), 'Positive'], 3 => [$item['id'], 'Integer']]); CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET processed_items=processed_items+1,error_items=error_items+1 WHERE domain_id=%1 AND run_id=%2', [1 => [self::domainId(), 'Positive'], 2 => [$run['run_id'], 'String']]); $summary['errors']++; $logs[] = $this->logRow($run, $item, $action, 'error', $message, $item['m365_user_id'] ?: NULL); }
  private function retryOrFail(array $run, array $item, string $message, ?int $retry): void { $attempt = $item['attempts'] + 1; if ($attempt >= self::MAX_ATTEMPTS) { $logs=[]; $summary=$this->getRun($run['run_id'])['summary']; $this->failItem($run,$item,$message,$run['phase']==='remove'?'member_remove_failed':'member_add_failed',$logs,$summary); $this->saveSummary($run['run_id'],$summary); $this->logMany($logs); return; } $delay=$retry??min(900,(2**$attempt)+random_int(0,3)); CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET state='retry',attempts=%1,available_date=DATE_ADD(NOW(),INTERVAL %2 SECOND),message=%3,modified_date=NOW() WHERE domain_id=%4 AND id=%5", [1=>[$attempt,'Integer'],2=>[$delay,'Integer'],3=>[$message,'String'],4=>[self::domainId(),'Positive'],5=>[$item['id'],'Integer']]); }
  private function deferSnapshot(array $run, Throwable $e): bool {
    if (!($e instanceof CRM_M365GroupSync_GraphException) || !in_array($e->httpStatus, [0,429,503,504], TRUE)) return FALSE;
    $attempt = $run['retry_count'] + 1; if ($attempt >= self::MAX_ATTEMPTS) return FALSE;
    $delay = $e->retryAfter ?? min(900,(2**$attempt)+random_int(0,3));
    CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status='retry_wait',retry_count=%1,next_retry_date=DATE_ADD(NOW(),INTERVAL %2 SECOND),heartbeat_date=NOW() WHERE domain_id=%3 AND run_id=%4",[1=>[$attempt,'Integer'],2=>[$delay,'Integer'],3=>[self::domainId(),'Positive'],4=>[$run['run_id'],'String']]);
    return TRUE;
  }
  private function waitForRetry(array $run): void { $next=CRM_Core_DAO::singleValueQuery("SELECT MIN(available_date) FROM civicrm_m365_sync_work WHERE domain_id=%1 AND run_id=%2 AND phase=%3 AND state='retry'",[1=>[self::domainId(),'Positive'],2=>[$run['run_id'],'String'],3=>[$run['phase'],'String']]); if($next) CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status='retry_wait',next_retry_date=%1,heartbeat_date=NOW() WHERE domain_id=%2 AND run_id=%3",[1=>[$next,'String'],2=>[self::domainId(),'Positive'],3=>[$run['run_id'],'String']]); }
  private function heartbeat(string $run,string $status): void { CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET status=%1,heartbeat_date=NOW(),next_retry_date=NULL WHERE domain_id=%2 AND run_id=%3',[1=>[$status,'String'],2=>[self::domainId(),'Positive'],3=>[$run,'String']]); }
  private function finish(string $run,array $summary,string $status): void { CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status=%1,phase='complete',completed_date=NOW(),heartbeat_date=NOW(),next_retry_date=NULL,summary=%2 WHERE domain_id=%3 AND run_id=%4",[1=>[$status,'String'],2=>[json_encode($summary),'String'],3=>[self::domainId(),'Positive'],4=>[$run,'String']]); }
  private function failRun(string $run,Throwable $e): void { try { $summary=$this->getRun($run)['summary']??[];$summary['error']=$e->getMessage();CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status='error',phase='complete',completed_date=NOW(),heartbeat_date=NOW(),summary=%1 WHERE domain_id=%2 AND run_id=%3",[1=>[json_encode($summary),'String'],2=>[self::domainId(),'Positive'],3=>[$run,'String']]); } catch(Throwable $ignored){ Civi::log()->error('Unable to record failed M365 run: {error}',['error'=>$ignored->getMessage()]); } }
  private function cancelRun(string $run): void { CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_work SET state='cancelled',modified_date=NOW() WHERE domain_id=%1 AND run_id=%2 AND state IN ('pending','retry')",[1=>[self::domainId(),'Positive'],2=>[$run,'String']]); CRM_Core_DAO::executeQuery("UPDATE civicrm_m365_sync_run SET status='cancelled',phase='complete',completed_date=NOW(),heartbeat_date=NOW(),next_retry_date=NULL WHERE domain_id=%1 AND run_id=%2",[1=>[self::domainId(),'Positive'],2=>[$run,'String']]); }
  private function saveSummary(string $run,array $summary): void { CRM_Core_DAO::executeQuery('UPDATE civicrm_m365_sync_run SET summary=%1,heartbeat_date=NOW() WHERE domain_id=%2 AND run_id=%3',[1=>[json_encode($summary),'String'],2=>[self::domainId(),'Positive'],3=>[$run,'String']]); }
  private function message(array $res): string { return (string)($res['body']['error']['message']??ts('Microsoft Graph request failed with status %1.',[1=>(int)($res['status']??0)])); }
  private function retryAfter(array $res): ?int { $v=$res['headers']['retry-after']??NULL;if($v===NULL)return NULL;if(is_numeric($v))return max(1,(int)$v);$time=strtotime((string)$v);return $time?max(1,$time-time()):NULL; }
  private function alreadyMember(array $res): bool { $message=strtolower($this->message($res));return (int)$res['status']===400&&(str_contains($message,'already exist')||str_contains($message,'already a member')); }
  private function client(): CRM_M365GroupSync_Service_Graph { return $this->graph??=new CRM_M365GroupSync_Service_Graph(); }
  private function randomId(): string { return CRM_Utils_String::createRandom(32,CRM_Utils_String::ALPHANUMERIC); }
  private function logRow(array $run,array $item,string $action,string $result,string $message,?string $user): array { return [self::domainId(),$run['run_id'],$run['civicrm_group_id'],$item['contact_id']?:0,$item['effective_email']?:'',$action,$result,$message,$run['m365_group_id'],$user?:'']; }
  private function logMany(array $rows): void {
    foreach(array_chunk($rows,100) as $chunk){$params=[];$values=[];$p=1;
      foreach($chunk as $row){$h=[];$types=['Integer','String','Integer','Integer','String','String','String','String','String','String'];foreach($row as $i=>$value){$params[$p]=[$value,$types[$i]];$h[]='%'.$p++;}$values[]=sprintf("(%s,%s,%s,NULLIF(%s,0),NULLIF(%s,''),%s,%s,%s,NULLIF(%s,''),NULLIF(%s,''),NOW())",...$h);}
      CRM_Core_DAO::executeQuery('INSERT INTO civicrm_m365_sync_log (domain_id,run_id,civicrm_group_id,civicrm_contact_id,effective_email,action,result,message,m365_group_id,m365_user_id,created_date) VALUES '.implode(',',$values),$params);
    }
  }

  public static function cleanupLogs(): int { $days=(int)Civi::settings()->get('m365_group_sync_retention_days');if($days<=0)return 0;$cutoff=date('Y-m-d H:i:s',strtotime('-'.$days.' days'));$domain=self::domainId();$ids=[];$dao=CRM_Core_DAO::executeQuery('SELECT run_id FROM civicrm_m365_sync_run WHERE domain_id=%1 AND started_date<%2',[1=>[$domain,'Positive'],2=>[$cutoff,'String']]);while($dao->fetch())$ids[]=$dao->run_id;foreach(array_chunk($ids,500)as$chunk){$q=CRM_Core_DAO::escapeStrings($chunk);CRM_Core_DAO::executeQuery("DELETE FROM civicrm_m365_sync_work WHERE domain_id=$domain AND run_id IN ($q)");CRM_Core_DAO::executeQuery("DELETE FROM civicrm_m365_sync_log WHERE domain_id=$domain AND run_id IN ($q)");CRM_Core_DAO::executeQuery("DELETE FROM civicrm_m365_sync_run WHERE domain_id=$domain AND run_id IN ($q)");}return count($ids); }
  private static function domainId(): int { return CRM_M365GroupSync_Service_Domain::id(); }
}
