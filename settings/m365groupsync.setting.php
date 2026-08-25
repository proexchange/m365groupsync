<?php

$base = [
  'group' => 'm365groupsync',
  'is_domain' => 1,
  'is_contact' => 0,
];

return [
  'm365_group_sync_enabled' => $base + ['name' => 'm365_group_sync_enabled', 'type' => 'Boolean', 'default' => FALSE, 'title' => ts('Enable automatic Microsoft 365 synchronization')],
  'm365_group_sync_retention_days' => $base + ['name' => 'm365_group_sync_retention_days', 'type' => 'Integer', 'default' => 90, 'title' => ts('Microsoft 365 sync log retention')],
  'm365_group_sync_auth_method' => $base + ['name' => 'm365_group_sync_auth_method', 'type' => 'String', 'default' => 'delegated', 'title' => ts('Microsoft 365 authentication method')],
  'm365_group_sync_tenant_id' => $base + ['name' => 'm365_group_sync_tenant_id', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft Entra tenant ID')],
  'm365_group_sync_client_id' => $base + ['name' => 'm365_group_sync_client_id', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft Entra application ID')],
  'm365_group_sync_client_secret' => $base + ['name' => 'm365_group_sync_client_secret', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft Entra application credential')],
  'm365_group_sync_access_token' => $base + ['name' => 'm365_group_sync_access_token', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft Graph access token')],
  'm365_group_sync_refresh_token' => $base + ['name' => 'm365_group_sync_refresh_token', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft Graph refresh token')],
  'm365_group_sync_token_expires' => $base + ['name' => 'm365_group_sync_token_expires', 'type' => 'Integer', 'default' => 0, 'title' => ts('Microsoft Graph token expiry')],
  'm365_group_sync_delegated_binding' => $base + ['name' => 'm365_group_sync_delegated_binding', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft delegated credential binding')],
  'm365_group_sync_application_binding' => $base + ['name' => 'm365_group_sync_application_binding', 'type' => 'String', 'default' => '', 'title' => ts('Microsoft application credential binding')],
  'm365_group_sync_connected_user' => $base + ['name' => 'm365_group_sync_connected_user', 'type' => 'String', 'default' => '', 'title' => ts('Connected Microsoft user')],
  'm365_group_sync_connected_tenant' => $base + ['name' => 'm365_group_sync_connected_tenant', 'type' => 'String', 'default' => '', 'title' => ts('Connected Microsoft tenant')],
  'm365_group_sync_last_auth' => $base + ['name' => 'm365_group_sync_last_auth', 'type' => 'String', 'default' => '', 'title' => ts('Last successful Microsoft authentication')],
  'm365_group_sync_invite_redirect_url' => $base + ['name' => 'm365_group_sync_invite_redirect_url', 'type' => 'String', 'default' => 'https://www.microsoft.com', 'title' => ts('Guest invitation redirect URL')],
  'm365_group_sync_last_auto_enqueue' => $base + ['name' => 'm365_group_sync_last_auto_enqueue', 'type' => 'Integer', 'default' => 0, 'title' => ts('Last automatic Microsoft 365 queue time')],
  'm365_group_sync_last_cleanup' => $base + ['name' => 'm365_group_sync_last_cleanup', 'type' => 'Integer', 'default' => 0, 'title' => ts('Last Microsoft 365 log cleanup time')],
];
