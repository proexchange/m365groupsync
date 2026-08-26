<?php

class CRM_M365GroupSync_Form_Admin extends CRM_Core_Form {
  private const CONNECTION_STATE_SETTINGS = [
    'm365_group_sync_auth_method',
    'm365_group_sync_tenant_id',
    'm365_group_sync_client_id',
    'm365_group_sync_client_secret',
    'm365_group_sync_access_token',
    'm365_group_sync_refresh_token',
    'm365_group_sync_token_expires',
    'm365_group_sync_delegated_binding',
    'm365_group_sync_application_binding',
    'm365_group_sync_connected_user',
    'm365_group_sync_connected_tenant',
    'm365_group_sync_last_auth',
  ];

  public function buildQuickForm(): void {
    $hasRefreshToken = (new CRM_M365GroupSync_Service_Auth())->secret('m365_group_sync_refresh_token') !== '';
    $this->add('checkbox', 'enabled', ts('Enable automatic Microsoft 365 synchronization'));
    $this->add('select', 'automatic_cadence', ts('Automatic synchronization cadence'), ['Hourly' => ts('Hourly'), 'Daily' => ts('Daily'), 'Weekly' => ts('Weekly'), 'Monthly' => ts('Monthly')]);
    $this->add('select', 'auth_method', ts('Authentication Method'), [
      'delegated' => ts('Sign in with Microsoft'),
      'application' => ts('Application Credentials'),
    ]);
    $this->add('text', 'tenant_id', ts('Tenant ID'), ['size' => 48]);
    $this->add('text', 'client_id', ts('Client / Application ID'), ['size' => 48]);
    $this->add('password', 'client_secret', ts('Client Secret'), ['size' => 48]);
    $this->add('select', 'retention_days', ts('Log retention'), [30 => ts('30 days'), 90 => ts('90 days'), 180 => ts('180 days'), 365 => ts('365 days'), 0 => ts('Never automatically delete')]);
    $this->addActionButton('connect', $hasRefreshToken ? ts('Reconnect Microsoft') : ts('Sign in with Microsoft'));
    $this->addActionButton('disconnect', ts('Disconnect'), 'fa-times');
    $this->addActionButton('test', ts('Test Connection'));
    $this->addActionButton('compare_all', ts('Compare All'));
    $this->addActionButton('dry_run_all', ts('Dry Run All'));
    $this->addActionButton('sync_all', ts('Sync All Now'));
    if (CRM_M365GroupSync_Service_Domain::isLegacyResolutionRequired()) {
      $this->addActionButton('claim_legacy', ts('Assign legacy data to this domain'), 'fa-exclamation-triangle');
    }
    $this->add('select', 'mapped_status', ts('Mapped Status'), [
      'mapped' => ts('Mapped'),
      'not_mapped' => ts('Not Mapped'),
      'all' => ts('All'),
    ], FALSE, ['class' => 'crm-form-select']);
    $this->addButtons([['type' => 'submit', 'name' => ts('Save settings'), 'isDefault' => TRUE]]);

    $mappings = CRM_M365GroupSync_Service_Mapping::all();
    foreach ($mappings as &$mapping) {
      $id = $mapping['civicrm_group_id'];
      $mapping['edit_url'] = CRM_Utils_System::url('civicrm/group/edit', 'reset=1&action=update&id=' . $id, FALSE, NULL, FALSE);
      $mapping['log_url'] = CRM_Utils_System::url('civicrm/admin/m365-group-sync/log', 'reset=1&gid=' . $id, FALSE, NULL, FALSE);
      foreach (['compare' => ts('Compare'), 'dry_run' => ts('Dry Run'), 'sync' => ts('Sync Now')] as $mode => $label) {
        $name = 'map_' . $mode . '_' . $id;
        $this->addActionButton($name, $label);
        $mapping[$mode . '_button'] = $name;
      }
    }
    unset($mapping);
    $this->assign('mappings', $mappings);
    $unmapped = CRM_M365GroupSync_Service_Mapping::unmappedGroups();
    foreach ($unmapped as &$group) {
      $group['edit_url'] = CRM_Utils_System::url('civicrm/group/edit', 'reset=1&action=update&id=' . $group['civicrm_group_id'], FALSE, NULL, FALSE);
    }
    unset($group);
    $this->assign('unmappedGroups', $unmapped);
    $this->assign('connectedUser', Civi::settings()->get('m365_group_sync_connected_user'));
    $this->assign('connectedTenant', Civi::settings()->get('m365_group_sync_connected_tenant'));
    $this->assign('lastAuth', Civi::settings()->get('m365_group_sync_last_auth'));
    $this->assign('hasRefreshToken', $hasRefreshToken);
    $this->assign('redirectUri', (new CRM_M365GroupSync_Service_Auth())->redirectUri());
    $this->assign('logUrl', CRM_Utils_System::url('civicrm/admin/m365-group-sync/log', 'reset=1', FALSE, NULL, FALSE));
    $this->assign('validationFindings', CRM_Core_Session::singleton()->get('m365_validation_findings') ?: []);
    $activeOperation = (string) CRM_Utils_Request::retrieve('op', 'String', NULL, FALSE, '', 'GET');
    if ($activeOperation === '') {
      $activeOperation = (string) CRM_Core_DAO::singleValueQuery(
        "SELECT operation_id FROM civicrm_m365_sync_run
          WHERE domain_id=%1 AND status IN ('queued','running','retry_wait')
            AND operation_id IS NOT NULL AND operation_id<>''
       ORDER BY started_date ASC,id ASC LIMIT 1",
        [1 => [CRM_M365GroupSync_Service_Domain::id(), 'Positive']]
      );
    }
    $this->assign('activeOperation', $activeOperation);
    $this->assign('legacyResolutionRequired', CRM_M365GroupSync_Service_Domain::isLegacyResolutionRequired());
    $this->assign('currentDomainId', CRM_M365GroupSync_Service_Domain::id());
    CRM_Core_Session::singleton()->set('m365_validation_findings', []);
  }

  public function setDefaultValues(): array {
    return [
      'enabled' => Civi::settings()->get('m365_group_sync_enabled'),
      'automatic_cadence' => Civi::settings()->get('m365_group_sync_automatic_cadence') ?: 'Hourly',
      'auth_method' => Civi::settings()->get('m365_group_sync_auth_method') ?: 'delegated',
      'tenant_id' => Civi::settings()->get('m365_group_sync_tenant_id'),
      'client_id' => Civi::settings()->get('m365_group_sync_client_id'),
      'retention_days' => Civi::settings()->get('m365_group_sync_retention_days') ?? 90,
      'mapped_status' => 'mapped',
    ];
  }

  private function addActionButton(string $name, string $label, string $icon = 'fa-check-circle'): void {
    $element = $this->createElement('xbutton', $name . '_button', CRM_Core_Page::crmIcon($icon) . ' ' . $label, [
      'type' => 'submit',
      'name' => $name,
      'value' => 1,
      'class' => 'crm-form-submit crm-button crm-button-type-' . $name,
    ]);
    $this->addGroup([$element], $name, '', '', FALSE);
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    if ($this->isAction('claim_legacy')) {
      try {
        CRM_M365GroupSync_Upgrader::claimLegacyDataForCurrentDomain();
        CRM_Core_Session::setStatus(ts('Legacy Microsoft 365 synchronization data was assigned to this CiviCRM domain.'), ts('Domain migration complete'), 'success');
      }
      catch (Throwable $e) {
        CRM_Core_Session::setStatus($e->getMessage(), ts('Domain migration could not complete'), 'error');
      }
      return;
    }
    if ($this->isAction('connect')) {
      try {
        $this->saveConnectionFields($values);
        CRM_Utils_System::redirect((new CRM_M365GroupSync_Service_Auth())->authorizationUrl());
      }
      catch (Throwable $e) {
        CRM_Core_Session::setStatus($e->getMessage(), ts('Unable to start Microsoft sign-in'), 'error');
      }
      return;
    }
    if ($this->isAction('disconnect')) {
      $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 5);
      if (!$lock->isAcquired()) {
        CRM_Core_Session::setStatus(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then disconnect again.'), ts('Disconnect deferred'), 'warning');
        return;
      }
      try {
        CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
        (new CRM_M365GroupSync_Service_Auth())->disconnect();
        CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
      }
      finally {
        $lock->release();
      }
      CRM_Core_Session::setStatus(ts('The delegated Microsoft account was disconnected and active runs were cancelled. Group mappings and logs were preserved.'), ts('Disconnected'), 'success');
      return;
    }
    if ($this->isAction('test')) {
      $this->testConnection($values);
      return;
    }
    foreach (CRM_M365GroupSync_Service_Mapping::all() as $mapping) {
      foreach (['compare', 'dry_run', 'sync'] as $mode) {
        if ($this->isAction('map_' . $mode . '_' . $mapping['civicrm_group_id'])) {
          $this->runMappings([$mapping], $mode);
          return;
        }
      }
    }
    foreach (['compare_all' => 'compare', 'dry_run_all' => 'dry_run', 'sync_all' => 'sync'] as $button => $mode) {
      if ($this->isAction($button)) {
        $this->runMappings(CRM_M365GroupSync_Service_Mapping::all(), $mode);
        return;
      }
    }

    $active = Civi::settings()->get('m365_group_sync_auth_method') ?: 'delegated';
    $requested = $values['auth_method'] ?? $active;
    try {
      $changes = $this->saveConnectionFields($values);
    }
    catch (Throwable $e) {
      CRM_Core_Session::setStatus($e->getMessage(), ts('Settings not saved'), 'error');
      return;
    }
    Civi::settings()->set('m365_group_sync_enabled', !empty($values['enabled']));
    Civi::settings()->set('m365_group_sync_automatic_cadence', (string) ($values['automatic_cadence'] ?? 'Hourly'));
    Civi::settings()->set('m365_group_sync_retention_days', (int) ($values['retention_days'] ?? 90));
    CRM_M365GroupSync_Upgrader::ensureScheduledJob(CRM_M365GroupSync_Service_Domain::id());
    if ($requested === $active) {
      Civi::settings()->set('m365_group_sync_auth_method', $active);
      if ($changes['identityChanged']) {
        CRM_Core_Session::setStatus(
          $active === 'delegated'
            ? ts('Settings saved and active runs cancelled. Sign in with Microsoft again before synchronizing.')
            : ts('Settings saved and active runs cancelled. Run Test Connection before synchronizing.'),
          ts('Microsoft connection must be revalidated'),
          'warning'
        );
      }
      elseif ($changes['secretChanged']) {
        CRM_Core_Session::setStatus(ts('Settings saved and active runs cancelled. Run Test Connection to validate the new client-secret Value before synchronizing.'), ts('Microsoft connection must be revalidated'), 'warning');
      }
      else {
        CRM_Core_Session::setStatus(ts('Microsoft 365 Group Sync settings saved.'), ts('Success'), 'success');
      }
    }
    else {
      CRM_Core_Session::setStatus(
        $requested === 'delegated'
          ? ts('Settings saved, but authentication was not switched. Use “Sign in with Microsoft” to validate and activate delegated authentication.')
          : ts('Settings saved, but authentication was not switched. Use “Test Connection” to validate and activate Application Credentials.'),
        ts('Authentication validation required'),
        'warning'
      );
    }
  }

  private function saveConnectionFields(array $values, bool $applySecurityEffects = TRUE): array {
    $lock = NULL;
    if ($applySecurityEffects) {
      $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 5);
      if (!$lock->isAcquired()) {
        throw new CRM_Core_Exception(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then save again.'));
      }
    }
    try {
      $settings = Civi::settings();
      $auth = new CRM_M365GroupSync_Service_Auth();
      $oldTenant = trim((string) $settings->get('m365_group_sync_tenant_id'));
      $oldClient = trim((string) $settings->get('m365_group_sync_client_id'));
      $oldMethod = (string) ($settings->get('m365_group_sync_auth_method') ?: 'delegated');
      $newTenant = trim((string) ($values['tenant_id'] ?? ''));
      $newClient = trim((string) ($values['client_id'] ?? ''));
      $newMethod = (string) ($values['auth_method'] ?? $oldMethod);
      if ($newTenant === '') {
        throw new CRM_Core_Exception(ts('Enter a Microsoft Entra Tenant ID or verified tenant domain.'));
      }
      if ($newClient === '') {
        throw new CRM_Core_Exception(ts('Enter a Microsoft Entra Application ID.'));
      }
      $providedSecret = (string) ($values['client_secret'] ?? '');
      $secretChanged = $providedSecret !== '' && !hash_equals($auth->secret('m365_group_sync_client_secret'), $providedSecret);
      $identityChanged = strcasecmp($oldTenant, $newTenant) !== 0 || strcasecmp($oldClient, $newClient) !== 0;
      $methodChanged = $newMethod !== $oldMethod;

      if ($applySecurityEffects && ($identityChanged || $secretChanged || $methodChanged)) {
        CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
      }
      $settings->set('m365_group_sync_tenant_id', $newTenant);
      $settings->set('m365_group_sync_client_id', $newClient);
      if ($providedSecret !== '') {
        $auth->storeSecret('m365_group_sync_client_secret', $providedSecret);
      }
      if ($applySecurityEffects) {
        if ($identityChanged || $secretChanged || $methodChanged) {
          $auth->invalidateApplicationBinding(FALSE);
          // Catch runs queued concurrently while the connection lock was held.
          CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
        }
        if ($identityChanged || $methodChanged) {
          $auth->disconnect();
        }
        elseif ($secretChanged) {
          $auth->invalidateAccessToken();
        }
      }
      return compact('identityChanged', 'secretChanged', 'methodChanged');
    }
    finally {
      if ($lock && $lock->isAcquired()) $lock->release();
    }
  }

  private function isAction(string $name): bool {
    return (bool) $this->getSubmitValue($name . '_button');
  }

  private function testConnection(array $values): void {
    $settings = Civi::settings();
    $auth = new CRM_M365GroupSync_Service_Auth();
    $lock = Civi::lockManager()->acquire(CRM_M365GroupSync_Service_Auth::connectionLockName(), 5);
    if (!$lock->isAcquired()) {
      CRM_Core_Session::setStatus(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then test again.'), ts('Connection test deferred'), 'warning');
      return;
    }
    $old = [];
    foreach (self::CONNECTION_STATE_SETTINGS as $name) {
      $old[$name] = $settings->get($name);
    }
    try {
      $changes = $this->saveConnectionFields($values, FALSE);
      $requested = $values['auth_method'] ?? ($old['m365_group_sync_auth_method'] ?: 'delegated');
      $settings->set('m365_group_sync_auth_method', $requested);
      if ($requested === 'delegated') {
        if ($changes['identityChanged'] || $changes['methodChanged']) {
          $auth->disconnect();
        }
        elseif ($changes['secretChanged']) {
          $auth->invalidateAccessToken(FALSE);
        }
      }
      else {
        // This temporary binding permits the candidate credentials to make the
        // validation calls. The full snapshot restores it if any check fails.
        $settings->set('m365_group_sync_application_binding', $auth->currentCredentialBinding());
      }
      $firstMap = CRM_M365GroupSync_Service_Mapping::all()[0] ?? NULL;
      $findings = (new CRM_M365GroupSync_Service_Graph())->validate($firstMap['m365_group_id'] ?? NULL);
      $hasError = (bool) array_filter($findings, fn($finding) => $finding['level'] === 'error');
      CRM_Core_Session::singleton()->set('m365_validation_findings', $findings);
      if ($hasError) {
        throw new CRM_Core_Exception(ts('The connection was reached, but one or more required capabilities are missing.'));
      }
      $info = (new CRM_M365GroupSync_Service_Graph())->connectionInfo();
      if ($changes['identityChanged'] || $changes['secretChanged'] || $changes['methodChanged']) {
        CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
      }
      if ($requested === 'application') {
        $auth->disconnect();
        $settings->set('m365_group_sync_auth_method', 'application');
        $settings->set('m365_group_sync_application_binding', $auth->currentCredentialBinding());
      }
      $settings->set('m365_group_sync_connected_user', $info['user'] ?? 'Application credentials');
      $settings->set('m365_group_sync_connected_tenant', $info['tenant'] ?? '');
      $settings->set('m365_group_sync_last_auth', date('Y-m-d H:i:s'));
      CRM_Core_Session::setStatus(ts('Microsoft Graph connection and required permission grants were validated.'), ts('Success'), 'success');
    }
    catch (Throwable $e) {
      foreach ($old as $name => $value) {
        $settings->set($name, $value);
      }
      CRM_Core_Session::setStatus($e->getMessage(), ts('Connection failed'), 'error');
    }
    finally {
      $lock->release();
    }
  }

  private function runMappings(array $mappings, string $mode): void {
    if (!$mappings) {
      CRM_Core_Session::setStatus(ts('No Microsoft 365 Groups are mapped.'), ts('Nothing to do'), 'info');
      return;
    }
    if ($mode !== 'compare') {
      try {
        $queued = (new CRM_M365GroupSync_Service_Sync())->startMany($mappings, $mode, 'manual');
        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/m365-group-sync', 'reset=1&op=' . urlencode($queued['operation_id']), FALSE, NULL, FALSE));
      }
      catch (Throwable $e) {
        CRM_Core_Session::setStatus($e->getMessage(), ts('Unable to queue synchronization'), 'error');
      }
      return;
    }
    $completed = 0;
    $failed = 0;
    $missing = 0;
    $extra = 0;
    foreach ($mappings as $mapping) {
      try {
        $result = (new CRM_M365GroupSync_Service_Sync())->run((int) $mapping['civicrm_group_id'], $mapping['m365_group_id'], $mode);
        $completed++;
        $missing += (int) ($result['missing'] ?? 0);
        $extra += (int) ($result['extra'] ?? 0);
        $failed += (int) ($result['errors'] ?? 0) > 0 ? 1 : 0;
      }
      catch (Throwable $e) {
        $failed++;
      }
    }
    $title = ts('Compare completed');
    CRM_Core_Session::setStatus(ts('%1 group(s) completed; %2 failed. Missing: %3. Extra: %4.', [1 => $completed, 2 => $failed, 3 => $missing, 4 => $extra]), $title, $failed ? 'warning' : 'success');
  }
}
