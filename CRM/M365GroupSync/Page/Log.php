<?php

class CRM_M365GroupSync_Page_Log extends CRM_Core_Page {
  private const RUNS_PER_PAGE = 10;
  private const EVENTS_PER_PAGE = 500;

  public function run(): void {
    CRM_Utils_System::setTitle(ts('Microsoft 365 Group Sync Log'));
    $groupId = (int) CRM_Utils_Request::retrieve('gid', 'Positive');
    $runPage = max(1, (int) CRM_Utils_Request::retrieve('run_page', 'Positive', NULL, FALSE, 1));
    $eventPage = max(1, (int) CRM_Utils_Request::retrieve('event_page', 'Positive', NULL, FALSE, 1));
    $filters = [
      'group' => $groupId ?: (int) CRM_Utils_Request::retrieve('event_group', 'Positive'),
      'action' => trim((string) CRM_Utils_Request::retrieve('event_action', 'String')),
      'result' => trim((string) CRM_Utils_Request::retrieve('event_result', 'String')),
      'search' => trim((string) CRM_Utils_Request::retrieve('event_search', 'String')),
    ];

    $domainId = CRM_M365GroupSync_Service_Domain::id();
    [$runWhere, $runParams] = $groupId
      ? ['WHERE r.domain_id=%1 AND r.civicrm_group_id=%2', [1 => [$domainId, 'Positive'], 2 => [$groupId, 'Positive']]]
      : ['WHERE r.domain_id=%1', [1 => [$domainId, 'Positive']]];
    $runTotal = (int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_m365_sync_run r $runWhere", $runParams);
    $runPage = $this->validPage($runPage, $runTotal, self::RUNS_PER_PAGE);
    $runOffset = ($runPage - 1) * self::RUNS_PER_PAGE;
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT r.run_id,r.operation_id,r.civicrm_group_id,g.title AS group_title,r.mode,r.source,r.status,r.phase,r.total_items,r.processed_items,r.error_items,r.started_date,r.completed_date,r.summary
         FROM civicrm_m365_sync_run r
    LEFT JOIN civicrm_group g ON g.id=r.civicrm_group_id
       $runWhere ORDER BY r.started_date DESC,r.id DESC LIMIT " . self::RUNS_PER_PAGE . " OFFSET $runOffset",
      $runParams
    );
    $runs = [];
    while ($dao->fetch()) {
      $runs[] = [
        'run_id' => $dao->run_id,
        'operation_id' => $dao->operation_id,
        'civicrm_group_id' => (int) $dao->civicrm_group_id,
        'group_title' => $dao->group_title ?: '#' . $dao->civicrm_group_id,
        'mode' => $dao->mode,
        'source' => $dao->source,
        'status' => $dao->status,
        'phase' => $dao->phase,
        'total_items' => (int) $dao->total_items,
        'processed_items' => (int) $dao->processed_items,
        'error_items' => (int) $dao->error_items,
        'started_date' => $dao->started_date,
        'completed_date' => $dao->completed_date,
        'summary' => json_decode((string) $dao->summary, TRUE) ?: [],
        'resume_url' => in_array($dao->status, ['queued', 'running', 'retry_wait'], TRUE) && $dao->operation_id
          ? CRM_Utils_System::url('civicrm/admin/m365-group-sync', 'reset=1&op=' . urlencode($dao->operation_id), FALSE, NULL, FALSE)
          : '',
      ];
    }

    [$eventWhere, $eventParams] = $this->eventWhere($filters, $domainId);
    $eventTotal = (int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_m365_sync_log l $eventWhere", $eventParams);
    $eventPage = $this->validPage($eventPage, $eventTotal, self::EVENTS_PER_PAGE);
    $eventOffset = ($eventPage - 1) * self::EVENTS_PER_PAGE;
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT l.*,g.title AS group_title FROM civicrm_m365_sync_log l LEFT JOIN civicrm_group g ON g.id=l.civicrm_group_id $eventWhere ORDER BY l.created_date DESC,l.id DESC LIMIT " . self::EVENTS_PER_PAGE . " OFFSET $eventOffset",
      $eventParams
    );
    $logs = [];
    while ($dao->fetch()) {
      $logs[] = [
        'created_date' => $dao->created_date,
        'run_id' => $dao->run_id,
        'group_title' => $dao->group_title ?: '#' . $dao->civicrm_group_id,
        'civicrm_contact_id' => $dao->civicrm_contact_id,
        'effective_email' => $dao->effective_email,
        'action' => $dao->action,
        'result' => $dao->result,
        'message' => $dao->message,
      ];
    }

    $base = ['reset' => 1];
    if ($groupId) $base['gid'] = $groupId;
    elseif ($filters['group']) $base['event_group'] = $filters['group'];
    if ($filters['action'] !== '') $base['event_action'] = $filters['action'];
    if ($filters['result'] !== '') $base['event_result'] = $filters['result'];
    if ($filters['search'] !== '') $base['event_search'] = $filters['search'];

    $this->assign('runs', $runs);
    $this->assign('logs', $logs);
    $this->assign('runPager', $this->pager($runPage, $runTotal, self::RUNS_PER_PAGE, 'run_page', $base + ['event_page' => $eventPage]));
    $this->assign('eventPager', $this->pager($eventPage, $eventTotal, self::EVENTS_PER_PAGE, 'event_page', $base + ['run_page' => $runPage]));
    $this->assign('eventRangeStart', $eventTotal ? $eventOffset + 1 : 0);
    $this->assign('eventRangeEnd', min($eventOffset + self::EVENTS_PER_PAGE, $eventTotal));
    $this->assign('eventTotal', $eventTotal);
    $this->assign('eventFilters', $filters);
    $this->assign('eventGroups', $this->groupOptions());
    $this->assign('eventActions', $this->distinctOptions('action', $groupId));
    $this->assign('eventResults', $this->distinctOptions('result', $groupId));
    $this->assign('fixedGroup', $groupId);
    $this->assign('logUrl', CRM_Utils_System::url('civicrm/admin/m365-group-sync/log', 'reset=1', FALSE, NULL, FALSE));
    $this->assign('clearLogUrl', $this->pageUrl($groupId ? ['reset' => 1, 'gid' => $groupId] : ['reset' => 1]));
    $this->assign('adminUrl', CRM_Utils_System::url('civicrm/admin/m365-group-sync', 'reset=1', FALSE, NULL, FALSE));
    parent::run();
  }

  private function eventWhere(array $filters, int $domainId): array {
    $clauses = ['l.domain_id=%1']; $params = [1 => [$domainId, 'Positive']]; $position = 2;
    if ($filters['group']) { $clauses[] = "l.civicrm_group_id=%$position"; $params[$position++] = [$filters['group'], 'Positive']; }
    if ($filters['action'] !== '') { $clauses[] = "l.action=%$position"; $params[$position++] = [$filters['action'], 'String']; }
    if ($filters['result'] !== '') { $clauses[] = "l.result=%$position"; $params[$position++] = [$filters['result'], 'String']; }
    if ($filters['search'] !== '') {
      $clauses[] = "(l.effective_email LIKE %$position OR l.message LIKE %$position OR CAST(l.civicrm_contact_id AS CHAR) LIKE %$position)";
      $params[$position] = ['%' . $filters['search'] . '%', 'String'];
    }
    return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
  }

  private function groupOptions(): array {
    $dao = CRM_Core_DAO::executeQuery('SELECT DISTINCT l.civicrm_group_id,g.title FROM civicrm_m365_sync_log l LEFT JOIN civicrm_group g ON g.id=l.civicrm_group_id WHERE l.domain_id=%1 ORDER BY g.title,l.civicrm_group_id', [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive']]);
    $options = [];
    while ($dao->fetch()) $options[(int) $dao->civicrm_group_id] = $dao->title ?: '#' . $dao->civicrm_group_id;
    return $options;
  }

  private function distinctOptions(string $field, int $groupId): array {
    if (!in_array($field, ['action', 'result'], TRUE)) throw new InvalidArgumentException('Invalid log option field.');
    $where = "WHERE domain_id=%1 AND `$field` IS NOT NULL AND `$field`<>''";
    $params = [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive']];
    if ($groupId) { $where .= ' AND civicrm_group_id=%2'; $params[2] = [$groupId, 'Positive']; }
    $dao = CRM_Core_DAO::executeQuery("SELECT DISTINCT `$field` AS value FROM civicrm_m365_sync_log $where ORDER BY `$field`", $params);
    $options = [];
    while ($dao->fetch()) $options[$dao->value] = ucwords(str_replace('_', ' ', $dao->value));
    return $options;
  }

  private function validPage(int $page, int $total, int $perPage): int {
    return min($page, max(1, (int) ceil($total / $perPage)));
  }

  private function pager(int $page, int $total, int $perPage, string $parameter, array $base): array {
    $pages = max(1, (int) ceil($total / $perPage));
    $links = [];
    $start = max(1, $page - 3); $end = min($pages, $start + 6); $start = max(1, $end - 6);
    for ($number = $start; $number <= $end; $number++) $links[$number] = $this->pageUrl($base + [$parameter => $number]);
    return [
      'current' => $page, 'pages' => $pages, 'total' => $total, 'links' => $links,
      'previous' => $page > 1 ? $this->pageUrl($base + [$parameter => $page - 1]) : '',
      'next' => $page < $pages ? $this->pageUrl($base + [$parameter => $page + 1]) : '',
    ];
  }

  private function pageUrl(array $query): string {
    return CRM_Utils_System::url('civicrm/admin/m365-group-sync/log', http_build_query($query), FALSE, NULL, FALSE);
  }
}
