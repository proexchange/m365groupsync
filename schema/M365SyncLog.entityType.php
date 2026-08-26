<?php

return [
  'name' => 'M365SyncLog',
  'table' => 'civicrm_m365_sync_log',
  'getInfo' => fn() => ['title' => ts('Microsoft 365 Sync Log'), 'title_plural' => ts('Microsoft 365 Sync Logs')],
  'getIndices' => fn() => [
    'I_domain_created' => ['fields' => ['domain_id' => TRUE, 'created_date' => TRUE]],
    'I_domain_run' => ['fields' => ['domain_id' => TRUE, 'run_id' => TRUE]],
  ],
  'getFields' => fn() => [
    'id' => ['title' => ts('Log ID'), 'sql_type' => 'bigint unsigned', 'input_type' => 'Number', 'required' => TRUE, 'primary_key' => TRUE, 'auto_increment' => TRUE],
    'domain_id' => CRM_M365GroupSync_EntitySchema::domainField(),
    'run_id' => CRM_M365GroupSync_EntitySchema::referenceField(ts('Sync Run'), 'M365SyncRun', 'run_id', TRUE, 'char(36)'),
    'civicrm_group_id' => CRM_M365GroupSync_EntitySchema::referenceField(ts('CiviCRM Group'), 'Group', 'id', TRUE),
    'civicrm_contact_id' => CRM_M365GroupSync_EntitySchema::referenceField(ts('Contact'), 'Contact', 'id', FALSE),
    'effective_email' => ['title' => ts('Email'), 'sql_type' => 'varchar(255)', 'input_type' => 'Email'],
    'action' => ['title' => ts('Action'), 'sql_type' => 'varchar(32)', 'input_type' => 'Text', 'required' => TRUE],
    'result' => ['title' => ts('Result'), 'sql_type' => 'varchar(16)', 'input_type' => 'Text', 'required' => TRUE],
    'message' => ['title' => ts('Message'), 'sql_type' => 'text', 'input_type' => NULL],
    'm365_group_id' => ['title' => ts('Microsoft 365 Group ID'), 'sql_type' => 'varchar(128)', 'input_type' => 'Text'],
    'm365_user_id' => ['title' => ts('Microsoft 365 User ID'), 'sql_type' => 'varchar(128)', 'input_type' => 'Text'],
    'created_date' => ['title' => ts('Created'), 'sql_type' => 'datetime', 'input_type' => NULL],
  ],
];
