<?php

use CRM_M365GroupSync_Service_Mapping as Mapping;

const M365GROUPSYNC_ADMIN_PERMISSION = 'administer Microsoft 365 group sync';

/** Define the permission which controls credentials, mappings, logs, and runs. */
function m365groupsync_civicrm_permission(&$permissions): void {
  $permissions[M365GROUPSYNC_ADMIN_PERMISSION] = [
    'label' => ts('Microsoft 365 Group Sync: administer synchronization'),
    'description' => ts('Configure Microsoft credentials and manage Microsoft 365 group mappings and synchronization.'),
    'implied_by' => ['administer CiviCRM'],
  ];
}

/** Register this extension's legacy CRM_ class path before page callbacks run. */
function m365groupsync_civicrm_config(&$config): void {
  static $configured = FALSE;
  if (!$configured) {
    $configured = TRUE;
    set_include_path(__DIR__ . PATH_SEPARATOR . get_include_path());
  }
}

function m365groupsync_civicrm_install(): void { CRM_M365GroupSync_Upgrader::install(); }
function m365groupsync_civicrm_uninstall(): void { CRM_M365GroupSync_Upgrader::uninstall(); }

function m365groupsync_civicrm_navigationMenu(&$menu): void {
  m365groupsync_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => ts('Microsoft 365 Group Sync'),
    'name' => 'm365_group_sync',
    'url' => 'civicrm/admin/m365-group-sync?reset=1',
    'permission' => M365GROUPSYNC_ADMIN_PERMISSION,
  ]);
}

/** Insert a hook-provided item into CiviCRM's nested navigation tree. */
function m365groupsync_insert_navigation_menu(array &$menu, string $path, array $item): bool {
  if ($path === '') {
    $menu[] = ['attributes' => array_merge(['label' => $item['name'], 'active' => 1], $item)];
    return TRUE;
  }
  $segments = explode('/', $path); $first = array_shift($segments);
  foreach ($menu as &$entry) {
    if (($entry['attributes']['name'] ?? NULL) === $first) {
      $entry['child'] ??= [];
      return m365groupsync_insert_navigation_menu($entry['child'], implode('/', $segments), $item);
    }
  }
  return FALSE;
}

function m365groupsync_civicrm_buildForm($formName, &$form): void {
  if (!m365groupsync_is_group_mapping_form($formName, $form) || !CRM_Core_Permission::check(M365GROUPSYNC_ADMIN_PERMISSION)) { return; }
  $groupId = (int) ($form->getVar('_id') ?: CRM_Utils_Request::retrieve('id', 'Positive', $form, FALSE));
  $options = ['' => ts('- Do not synchronize -')];
  try { foreach ((new CRM_M365GroupSync_Service_Graph())->groups() as $group) {
    if (!Mapping::isMappedElsewhere($group['id'], $groupId)) { $options[$group['id']] = trim(($group['displayName'] ?? $group['id']) . ' <' . ($group['mail'] ?? '') . '>'); }
  }} catch (Throwable $e) { $options[''] = ts('- Microsoft connection required -'); }
  $form->add('select', 'm365_group_id', ts('Microsoft 365 Group'), $options, FALSE, ['class' => 'crm-select2']);
  if ($groupId) { $form->setDefaults(['m365_group_id' => Mapping::getM365Id($groupId)]); }
  CRM_Core_Region::instance('form-bottom')->add(['template' => 'CRM/M365GroupSync/Form/GroupMapping.tpl']);
}

function m365groupsync_civicrm_postProcess($formName, &$form): void {
  if (!m365groupsync_is_group_mapping_form($formName, $form) || !CRM_Core_Permission::check(M365GROUPSYNC_ADMIN_PERMISSION)) { return; }
  $groupId = (int) $form->getVar('_id'); $values = $form->exportValues();
  if ($groupId && array_key_exists('m365_group_id', $values)) { Mapping::save($groupId, (string) $values['m365_group_id']); }
}

function m365groupsync_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors): void {
  if (!m365groupsync_is_group_mapping_form($formName, $form) || !CRM_Core_Permission::check(M365GROUPSYNC_ADMIN_PERMISSION) || empty($fields['m365_group_id'])) { return; }
  $groupId = (int) ($form->getVar('_id') ?: CRM_Utils_Request::retrieve('id', 'Positive', $form, FALSE));
  if (Mapping::isMappedElsewhere((string) $fields['m365_group_id'], $groupId)) {
    $errors['m365_group_id'] = ts('This Microsoft 365 Group is already mapped to another CiviCRM Group.');
  }
  if (empty($errors['m365_group_id'])) {
    try {
      Mapping::validateMicrosoftGroup((string) $fields['m365_group_id']);
    }
    catch (Throwable $e) {
      $errors['m365_group_id'] = $e->getMessage();
    }
  }
}

/** Show and process mappings only while creating or editing a group. */
function m365groupsync_is_group_mapping_form(string $formName, CRM_Core_Form $form): bool {
  if ($formName !== 'CRM_Group_Form_Edit') {
    return FALSE;
  }
  $action = (int) $form->getAction();
  return !($action & CRM_Core_Action::DELETE)
    && (bool) ($action & (CRM_Core_Action::ADD | CRM_Core_Action::UPDATE));
}

/** Deleting a CiviCRM Group only removes its local mapping; Microsoft is untouched. */
function m365groupsync_civicrm_pre($op, $objectName, $id, &$params): void {
  if ($objectName === 'Group' && $op === 'delete' && $id) {
    Mapping::deleteForGroup((int) $id);
  }
}
