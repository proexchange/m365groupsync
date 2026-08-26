<?php

return [
  'name' => 'M365SyncRun',
  'table' => 'civicrm_m365_sync_run',
  'getInfo' => fn() => ['title' => ts('Microsoft 365 Sync Run'), 'title_plural' => ts('Microsoft 365 Sync Runs')],
  'getIndices' => fn() => [
    'UI_run_id' => ['fields' => ['run_id' => TRUE], 'unique' => TRUE],
    'I_domain_operation' => ['fields' => ['domain_id' => TRUE, 'operation_id' => TRUE]],
    'I_domain_worker' => ['fields' => ['domain_id' => TRUE, 'status' => TRUE, 'next_retry_date' => TRUE, 'heartbeat_date' => TRUE]],
  ],
  'getFields' => fn() => [
    'id' => ['title' => ts('Sync Run ID'), 'sql_type' => 'bigint unsigned', 'input_type' => 'Number', 'required' => TRUE, 'primary_key' => TRUE, 'auto_increment' => TRUE],
    'domain_id' => CRM_M365GroupSync_EntitySchema::domainField(),
    'run_id' => ['title' => ts('Run Identifier'), 'sql_type' => 'char(36)', 'input_type' => 'Text', 'required' => TRUE],
    'operation_id' => ['title' => ts('Operation Identifier'), 'sql_type' => 'char(36)', 'input_type' => 'Text'],
    'civicrm_group_id' => CRM_M365GroupSync_EntitySchema::referenceField(ts('CiviCRM Group'), 'Group', 'id', TRUE),
    'm365_group_id' => ['title' => ts('Microsoft 365 Group ID'), 'sql_type' => 'varchar(128)', 'input_type' => 'Text', 'required' => TRUE],
    'mode' => ['title' => ts('Mode'), 'sql_type' => 'varchar(16)', 'input_type' => 'Text', 'required' => TRUE],
    'source' => ['title' => ts('Source'), 'sql_type' => 'varchar(16)', 'input_type' => 'Text', 'required' => TRUE],
    'status' => ['title' => ts('Status'), 'sql_type' => 'varchar(32)', 'input_type' => 'Text', 'required' => TRUE],
    'phase' => ['title' => ts('Phase'), 'sql_type' => 'varchar(16)', 'input_type' => 'Text', 'required' => TRUE],
    'started_date' => ['title' => ts('Started'), 'sql_type' => 'datetime', 'input_type' => NULL],
    'completed_date' => ['title' => ts('Completed'), 'sql_type' => 'datetime', 'input_type' => NULL],
    'summary' => ['title' => ts('Summary'), 'sql_type' => 'longtext', 'input_type' => NULL],
  ],
];
