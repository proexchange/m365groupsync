<?php

return [
  'name' => 'M365GroupMapping',
  'table' => 'civicrm_m365_group_mapping',
  'getInfo' => fn() => ['title' => ts('Microsoft 365 Group Mapping'), 'title_plural' => ts('Microsoft 365 Group Mappings')],
  'getIndices' => fn() => [
    'UI_domain_group' => ['fields' => ['domain_id' => TRUE, 'civicrm_group_id' => TRUE], 'unique' => TRUE],
    'UI_m365_group' => ['fields' => ['m365_group_id' => TRUE], 'unique' => TRUE],
  ],
  'getFields' => fn() => [
    'id' => ['title' => ts('Mapping ID'), 'sql_type' => 'bigint unsigned', 'input_type' => 'Number', 'required' => TRUE, 'primary_key' => TRUE, 'auto_increment' => TRUE],
    'domain_id' => CRM_M365GroupSync_EntitySchema::domainField(),
    'civicrm_group_id' => CRM_M365GroupSync_EntitySchema::referenceField(ts('CiviCRM Group'), 'Group', 'id', TRUE),
    'm365_group_id' => ['title' => ts('Microsoft 365 Group ID'), 'sql_type' => 'varchar(128)', 'input_type' => 'Text', 'required' => TRUE],
    'm365_display_name' => ['title' => ts('Microsoft 365 Group Name'), 'sql_type' => 'varchar(255)', 'input_type' => 'Text'],
    'm365_mail' => ['title' => ts('Microsoft 365 Group Email'), 'sql_type' => 'varchar(255)', 'input_type' => 'Email'],
    'created_date' => ['title' => ts('Created'), 'sql_type' => 'datetime', 'input_type' => NULL],
    'modified_date' => ['title' => ts('Modified'), 'sql_type' => 'datetime', 'input_type' => NULL],
  ],
];
