<?php

/** Microsoft identity-platform authorization and token lifecycle. */
class CRM_M365GroupSync_Service_Auth {
  public const DELEGATED_SCOPES = 'openid profile offline_access User.Read Group.Read.All GroupMember.ReadWrite.All User.Read.All User.Invite.All';

  public static function connectionLockName(): string {
    return 'data.m365groupsync.connection';
  }

  public function currentCredentialBinding(): string {
    $tenant = strtolower(trim((string) Civi::settings()->get('m365_group_sync_tenant_id')));
    $client = strtolower(trim((string) Civi::settings()->get('m365_group_sync_client_id')));
    return hash('sha256', ($tenant ?: 'organizations') . "\0" . $client);
  }

  public function redirectUri(): string {
    return CRM_Utils_System::url('civicrm/admin/m365-group-sync/oauth/callback', NULL, TRUE, NULL, FALSE, TRUE);
  }

  public function authorizationUrl(): string {
    $tenant = $this->tenant(TRUE);
    $clientId = (string) Civi::settings()->get('m365_group_sync_client_id');
    if ($clientId === '') {
      throw new CRM_Core_Exception(ts('Enter and save a Client / Application ID before signing in.'));
    }
    $state = bin2hex(random_bytes(32));
    $verifier = CRM_Utils_String::base64UrlEncode(random_bytes(64));
    $session = CRM_Core_Session::singleton();
    $session->set('m365_oauth_state', $state);
    $session->set('m365_oauth_verifier', $verifier);
    $session->set('m365_oauth_started', time());
    $query = http_build_query([
      'client_id' => $clientId,
      'response_type' => 'code',
      'redirect_uri' => $this->redirectUri(),
      'response_mode' => 'query',
      'scope' => self::DELEGATED_SCOPES,
      'state' => $state,
      'prompt' => 'select_account',
      'code_challenge' => CRM_Utils_String::base64UrlEncode(hash('sha256', $verifier, TRUE)),
      'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
    return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . $query;
  }

  public function complete(string $code, string $state): void {
    $session = CRM_Core_Session::singleton();
    $expected = (string) $session->get('m365_oauth_state');
    $started = (int) $session->get('m365_oauth_started');
    $verifier = (string) $session->get('m365_oauth_verifier');
    $this->clearOauthSession();
    if ($expected === '' || !hash_equals($expected, $state) || !$started || time() - $started > 900 || $verifier === '') {
      throw new CRM_Core_Exception(ts('The Microsoft sign-in response could not be verified. Please try connecting again.'));
    }
    $lock = Civi::lockManager()->acquire(self::connectionLockName(), 5);
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception(ts('A synchronization batch is using the Microsoft connection. Wait for it to finish, then sign in again.'));
    }
    try {
      $token = $this->tokenRequest([
        'client_id' => (string) Civi::settings()->get('m365_group_sync_client_id'),
        'client_secret' => $this->secret('m365_group_sync_client_secret'),
        'code' => $code,
        'redirect_uri' => $this->redirectUri(),
        'grant_type' => 'authorization_code',
        'scope' => self::DELEGATED_SCOPES,
        'code_verifier' => $verifier,
      ]);
      if (empty($token['refresh_token'])) {
        throw new CRM_Core_Exception(ts('Microsoft did not return a refresh token. Confirm that offline_access is permitted, then reconnect.'));
      }
      CRM_M365GroupSync_Service_Sync::requestCancellationForAll();
      $this->storeToken($token);
      Civi::settings()->set('m365_group_sync_delegated_binding', $this->currentCredentialBinding());
      Civi::settings()->set('m365_group_sync_application_binding', '');
      Civi::settings()->set('m365_group_sync_auth_method', 'delegated');
      try {
        $identity = (new CRM_M365GroupSync_Service_Graph())->connectionInfo();
      }
      catch (Throwable $e) {
        $this->disconnect();
        throw $e;
      }
      Civi::settings()->set('m365_group_sync_connected_user', $identity['user'] ?? '');
      Civi::settings()->set('m365_group_sync_connected_tenant', $identity['tenant'] ?? '');
      Civi::settings()->set('m365_group_sync_last_auth', date('Y-m-d H:i:s'));
    }
    finally {
      $lock->release();
    }
  }

  public function accessToken(): string {
    $binding = (string) Civi::settings()->get('m365_group_sync_delegated_binding');
    if ($binding === '' || !hash_equals($binding, $this->currentCredentialBinding())) {
      $this->disconnect();
      throw new CRM_Core_Exception(ts('The Microsoft authorization does not match the configured Tenant ID and Application ID. Sign in with Microsoft again.'));
    }
    $access = $this->secret('m365_group_sync_access_token');
    $expires = (int) Civi::settings()->get('m365_group_sync_token_expires');
    if ($access !== '' && $expires > time() + 120) {
      return $access;
    }
    $refresh = $this->secret('m365_group_sync_refresh_token');
    if ($refresh === '') {
      throw new CRM_Core_Exception(ts('Microsoft authorization is required. Use Sign in with Microsoft to connect.'));
    }
    try {
      $token = $this->tokenRequest([
        'client_id' => (string) Civi::settings()->get('m365_group_sync_client_id'),
        'client_secret' => $this->secret('m365_group_sync_client_secret'),
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
        'scope' => self::DELEGATED_SCOPES,
      ]);
    }
    catch (Throwable $e) {
      Civi::settings()->set('m365_group_sync_access_token', '');
      Civi::settings()->set('m365_group_sync_token_expires', 0);
      throw new CRM_Core_Exception(ts('Microsoft authorization must be renewed: %1', [1 => $e->getMessage()]));
    }
    $this->storeToken($token, $refresh);
    Civi::settings()->set('m365_group_sync_last_auth', date('Y-m-d H:i:s'));
    return $this->secret('m365_group_sync_access_token');
  }

  public function applicationToken(): string {
    $binding = (string) Civi::settings()->get('m365_group_sync_application_binding');
    if ($binding === '' || !hash_equals($binding, $this->currentCredentialBinding())) {
      throw new CRM_Core_Exception(ts('The application credentials have not been validated for the configured Tenant ID and Application ID. Run Test Connection.'));
    }
    $tenant = $this->tenant(TRUE);
    $client = (string) Civi::settings()->get('m365_group_sync_client_id');
    $secret = $this->secret('m365_group_sync_client_secret');
    if ($client === '' || $secret === '') {
      throw new CRM_Core_Exception(ts('Microsoft application credentials are incomplete.'));
    }
    $token = $this->tokenRequest([
      'client_id' => $client,
      'client_secret' => $secret,
      'scope' => 'https://graph.microsoft.com/.default',
      'grant_type' => 'client_credentials',
    ]);
    if (empty($token['access_token'])) {
      throw new CRM_Core_Exception(ts('Microsoft token request did not return an access token.'));
    }
    return $token['access_token'];
  }

  public function disconnect(): void {
    foreach (['m365_group_sync_access_token', 'm365_group_sync_refresh_token', 'm365_group_sync_token_expires', 'm365_group_sync_connected_user', 'm365_group_sync_connected_tenant', 'm365_group_sync_last_auth', 'm365_group_sync_delegated_binding'] as $name) {
      Civi::settings()->set($name, $name === 'm365_group_sync_token_expires' ? 0 : '');
    }
    $this->clearOauthSession();
  }

  /** Force the next delegated request to validate the current client secret. */
  public function invalidateAccessToken(bool $clearConnectionMetadata = TRUE): void {
    Civi::settings()->set('m365_group_sync_access_token', '');
    Civi::settings()->set('m365_group_sync_token_expires', 0);
    if ($clearConnectionMetadata) {
      foreach (['m365_group_sync_connected_user', 'm365_group_sync_connected_tenant', 'm365_group_sync_last_auth'] as $name) {
        Civi::settings()->set($name, '');
      }
    }
  }

  public function invalidateApplicationBinding(bool $clearConnectionMetadata = TRUE): void {
    Civi::settings()->set('m365_group_sync_application_binding', '');
    if ($clearConnectionMetadata) {
      foreach (['m365_group_sync_connected_user', 'm365_group_sync_connected_tenant', 'm365_group_sync_last_auth'] as $name) {
        Civi::settings()->set($name, '');
      }
    }
  }

  public function storeSecret(string $name, string $value): void {
    Civi::settings()->set($name, $value === '' ? '' : Civi::service('crypto.token')->encrypt($value, 'CRED'));
  }

  public function secret(string $name): string {
    $value = (string) Civi::settings()->get($name);
    if ($value === '') {
      return '';
    }
    return (string) Civi::service('crypto.token')->decrypt($value, '*');
  }

  private function storeToken(array $token, string $fallbackRefresh = ''): void {
    $this->storeSecret('m365_group_sync_access_token', (string) ($token['access_token'] ?? ''));
    $this->storeSecret('m365_group_sync_refresh_token', (string) ($token['refresh_token'] ?? $fallbackRefresh));
    Civi::settings()->set('m365_group_sync_token_expires', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
  }

  private function tokenRequest(array $params): array {
    if (empty($params['client_secret'])) {
      unset($params['client_secret']);
    }
    try {
      $response = (new GuzzleHttp\Client(['timeout' => 30]))->post(
        'https://login.microsoftonline.com/' . rawurlencode($this->tenant(TRUE)) . '/oauth2/v2.0/token',
        ['form_params' => $params]
      );
      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('The token response was incomplete.');
      }
      return $data;
    }
    catch (GuzzleHttp\Exception\RequestException $e) {
      $detail = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
      $decoded = json_decode($detail, TRUE);
      throw new CRM_Core_Exception((string) ($decoded['error_description'] ?? $decoded['error']['message'] ?? $detail));
    }
  }

  private function tenant(bool $required = FALSE): string {
    $tenant = trim((string) Civi::settings()->get('m365_group_sync_tenant_id'));
    if ($tenant === '' && $required) {
      throw new CRM_Core_Exception(ts('Enter a Microsoft Entra Tenant ID.'));
    }
    return $tenant ?: 'organizations';
  }

  private function clearOauthSession(): void {
    $session = CRM_Core_Session::singleton();
    foreach (['m365_oauth_state', 'm365_oauth_verifier', 'm365_oauth_started'] as $key) {
      $session->set($key, NULL);
    }
  }
}
