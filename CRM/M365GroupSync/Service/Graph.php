<?php

class CRM_M365GroupSync_GraphException extends CRM_Core_Exception {
  public int $httpStatus;
  public ?int $retryAfter;

  public function __construct(string $message, int $httpStatus = 0, ?int $retryAfter = NULL) {
    parent::__construct($message);
    $this->httpStatus = $httpStatus;
    $this->retryAfter = $retryAfter;
  }
}

/** Minimal Microsoft Graph client limited to identity and group membership. */
class CRM_M365GroupSync_Service_Graph {
  private string $base = 'https://graph.microsoft.com/v1.0';
  private ?string $tokenCache = NULL;
  private GuzzleHttp\ClientInterface $client;

  public function __construct(?GuzzleHttp\ClientInterface $client = NULL) {
    $this->client = $client ?: new GuzzleHttp\Client(['timeout' => 30]);
  }

  public function groups(): array {
    // Filtering groupTypes with OData is documented by Graph, but some tenants
    // reject the filter/property combination. Fetch portable basic fields and
    // identify Unified (Microsoft 365) groups locally instead.
    $groups = $this->paged('/groups?$select=id,displayName,mail,mailEnabled,groupTypes');
    return array_values(array_filter($groups, static fn(array $group): bool =>
      in_array('Unified', (array) ($group['groupTypes'] ?? []), TRUE)
      && !empty($group['mailEnabled'])
      && trim((string) ($group['mail'] ?? '')) !== ''
    ));
  }

  public function group(string $id): array {
    return $this->request('GET', '/groups/' . rawurlencode($id) . '?$select=id,displayName,mail,mailEnabled,groupTypes');
  }

  public function members(string $id): array {
    return $this->paged('/groups/' . rawurlencode($id) . '/members/microsoft.graph.user?$select=id,mail,userPrincipalName,userType,displayName,otherMails');
  }

  public function owners(string $id): array {
    return $this->paged('/groups/' . rawurlencode($id) . '/owners?$select=id,displayName,userPrincipalName');
  }

  /** Resolve an existing Microsoft user or guest and reject ambiguous matches. */
  public function findUser(string $email): ?array {
    $email = strtolower(trim($email));
    $escaped = str_replace("'", "''", $email);
    $select = '$select=id,mail,userPrincipalName,userType,otherMails,displayName&$count=true&';
    $headers = ['ConsistencyLevel' => 'eventual'];
    // userType is occasionally absent or delayed in directory indexes. Match the
    // requested identity first, then use the returned object regardless of whether
    // it is a Member or Guest.
    $matches = $this->paged('/users?' . $select . '$filter=mail eq \'%s\' or userPrincipalName eq \'%s\' or otherMails/any(c:c eq \'%s\')', $headers, [$escaped, $escaped, $escaped]);
    if (count($matches) > 1) {
      throw new CRM_Core_Exception(ts('Multiple Microsoft users match %1; resolve the duplicate identities before syncing.', [1 => $email]));
    }
    return $matches[0] ?? NULL;
  }

  public function inviteGuest(string $email): array {
    return $this->request('POST', '/invitations', [
      'invitedUserEmailAddress' => $email,
      'inviteRedirectUrl' => Civi::settings()->get('m365_group_sync_invite_redirect_url') ?: 'https://www.microsoft.com',
      'sendInvitationMessage' => FALSE,
    ]);
  }

  public function addMember(string $groupId, string $userId): void {
    $this->request('POST', '/groups/' . rawurlencode($groupId) . '/members/$ref', ['@odata.id' => $this->base . '/directoryObjects/' . rawurlencode($userId)]);
  }

  public function removeMember(string $groupId, string $userId): void {
    $this->request('DELETE', '/groups/' . rawurlencode($groupId) . '/members/' . rawurlencode($userId) . '/$ref');
  }

  /**
   * Execute up to twenty independent Microsoft Graph requests in one HTTP call.
   *
   * The caller supplies stable string keys. Graph's outer response can be 200
   * while individual requests are throttled or rejected, so every inner status
   * and its Retry-After header are returned for the worker to handle separately.
   */
  public function batch(array $requests): array {
    if (!$requests || count($requests) > 20) {
      throw new CRM_Core_Exception(ts('A Microsoft Graph batch must contain between 1 and 20 requests.'));
    }
    $payload = [];
    $keys = [];
    $number = 1;
    foreach ($requests as $key => $request) {
      $id = (string) $number++;
      $url = (string) ($request['url'] ?? '');
      if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        throw new CRM_Core_Exception(ts('Microsoft Graph batch URLs must be relative to the Graph endpoint.'));
      }
      $entry = [
        'id' => $id,
        'method' => strtoupper((string) ($request['method'] ?? 'GET')),
        'url' => '/' . ltrim($url, '/'),
      ];
      if (!empty($request['headers'])) {
        $entry['headers'] = $request['headers'];
      }
      if (array_key_exists('body', $request)) {
        $entry['headers']['Content-Type'] = 'application/json';
        $entry['body'] = $request['body'];
      }
      $keys[$id] = $key;
      $payload[] = $entry;
    }
    $response = $this->request('POST', '/$batch', ['requests' => $payload]);
    $out = [];
    foreach ((array) ($response['responses'] ?? []) as $item) {
      $id = (string) ($item['id'] ?? '');
      if (!array_key_exists($id, $keys)) {
        continue;
      }
      $headers = [];
      foreach ((array) ($item['headers'] ?? []) as $name => $value) {
        $headers[strtolower((string) $name)] = $value;
      }
      $out[$keys[$id]] = [
        'status' => (int) ($item['status'] ?? 0),
        'headers' => $headers,
        'body' => is_array($item['body'] ?? NULL) ? $item['body'] : [],
      ];
    }
    foreach ($requests as $key => $_request) {
      $out[$key] ??= [
        'status' => 0,
        'headers' => [],
        'body' => ['error' => ['message' => ts('Microsoft Graph omitted this request from its batch response.')]],
      ];
    }
    return $out;
  }

  public function userLookupBatch(array $emails): array {
    $requests = [];
    foreach ($emails as $key => $email) {
      $email = strtolower(trim((string) $email));
      $escaped = str_replace("'", "''", $email);
      $filter = sprintf("mail eq '%s' or userPrincipalName eq '%s' or otherMails/any(c:c eq '%s')", $escaped, $escaped, $escaped);
      $requests[$key] = [
        'method' => 'GET',
        'url' => '/users?$select=id,mail,userPrincipalName,userType,otherMails,displayName&$count=true&$filter=' . rawurlencode($filter),
        'headers' => ['ConsistencyLevel' => 'eventual'],
      ];
    }
    return $this->batch($requests);
  }

  public function invitationBatch(array $emails): array {
    $requests = [];
    foreach ($emails as $key => $email) {
      $requests[$key] = [
        'method' => 'POST',
        'url' => '/invitations',
        'body' => [
          'invitedUserEmailAddress' => $email,
          'inviteRedirectUrl' => Civi::settings()->get('m365_group_sync_invite_redirect_url') ?: 'https://www.microsoft.com',
          'sendInvitationMessage' => FALSE,
        ],
      ];
    }
    return $this->batch($requests);
  }

  public function addMemberBatch(string $groupId, array $userIds): array {
    $requests = [];
    foreach ($userIds as $key => $userId) {
      $requests[$key] = [
        'method' => 'POST',
        'url' => '/groups/' . rawurlencode($groupId) . '/members/$ref',
        'body' => ['@odata.id' => $this->base . '/directoryObjects/' . rawurlencode((string) $userId)],
      ];
    }
    return $this->batch($requests);
  }

  public function removeMemberBatch(string $groupId, array $userIds): array {
    $requests = [];
    foreach ($userIds as $key => $userId) {
      $requests[$key] = [
        'method' => 'DELETE',
        'url' => '/groups/' . rawurlencode($groupId) . '/members/' . rawurlencode((string) $userId) . '/$ref',
      ];
    }
    return $this->batch($requests);
  }

  public function connectionInfo(): array {
    $claims = $this->tokenClaims($this->token());
    $info = ['tenant' => $claims['tid'] ?? (Civi::settings()->get('m365_group_sync_tenant_id') ?: '')];
    if ((Civi::settings()->get('m365_group_sync_auth_method') ?: 'delegated') === 'delegated') {
      $me = $this->request('GET', '/me?$select=id,displayName,mail,userPrincipalName');
      $info['user'] = $me['mail'] ?? $me['userPrincipalName'] ?? $me['displayName'] ?? '';
    }
    else {
      $info['user'] = 'Application credentials';
    }
    return $info;
  }

  /** Read-only capability report based on token grants plus live Graph reads. */
  public function validate(?string $groupId = NULL): array {
    $findings = [];
    try {
      $info = $this->connectionInfo();
      $findings[] = ['level' => 'success', 'label' => ts('Microsoft Graph authentication is valid')];
      $findings[] = ['level' => 'success', 'label' => ts('Connected tenant: %1', [1 => $info['tenant'] ?: ts('Unknown')])];
      if (!empty($info['user'])) {
        $findings[] = ['level' => 'success', 'label' => ts('Connected user: %1', [1 => $info['user']])];
      }
      $groups = $this->groups();
      $findings[] = ['level' => 'success', 'label' => ts('Microsoft 365 Groups can be read (%1 available)', [1 => count($groups)])];
      if ($groupId) {
        $group = $this->group($groupId);
        $members = $this->members($groupId);
        $owners = $this->owners($groupId);
        $findings[] = ['level' => 'success', 'label' => ts('Mapped group and membership can be read (%1 members, %2 owners)', [1 => count($members), 2 => count($owners)])];
        if (empty($group['mailEnabled']) || empty($group['mail'])) {
          $findings[] = ['level' => 'error', 'label' => ts('The mapped group is not mail enabled')];
        }
        else {
          $findings[] = ['level' => 'success', 'label' => ts('Mapped group is mail enabled: %1', [1 => $group['mail']])];
        }
        $findings[] = ['level' => 'warning', 'label' => ts('Confirm by acceptance test that newly added members receive group conversations in their inbox; subscription behavior is not changed by this extension.')];
        $findings[] = ['level' => 'warning', 'label' => ts('Microsoft Graph does not expose all Exchange posting restrictions. Confirm by acceptance test that guest members can post and non-members are rejected; this validator does not change those settings.')];
      }
      foreach ($this->permissionFindings() as $finding) {
        $findings[] = $finding;
      }
    }
    catch (Throwable $e) {
      $findings[] = ['level' => 'error', 'label' => $e->getMessage()];
    }
    return $findings;
  }

  private function permissionFindings(): array {
    $claims = $this->tokenClaims($this->token());
    $delegated = isset($claims['scp']);
    $grants = $delegated ? preg_split('/\s+/', (string) $claims['scp']) : (array) ($claims['roles'] ?? []);
    $required = [
      'Group.Read.All' => ['Group.Read.All', 'Group.ReadWrite.All', 'Directory.Read.All', 'Directory.ReadWrite.All'],
      'GroupMember.ReadWrite.All' => ['GroupMember.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.ReadWrite.All'],
      'User.Read.All' => ['User.Read.All', 'User.ReadWrite.All', 'Directory.Read.All', 'Directory.ReadWrite.All'],
      'User.Invite.All' => ['User.Invite.All', 'User.ReadWrite.All', 'Directory.ReadWrite.All'],
    ];
    $out = [];
    foreach ($required as $permission => $alternatives) {
      $out[] = array_intersect($alternatives, $grants)
        ? ['level' => 'success', 'label' => ts('Permission granted: %1', [1 => $permission])]
        : ['level' => 'error', 'label' => ts('Required Microsoft Graph permission is missing: %1', [1 => $permission])];
    }
    if ($delegated) {
      $out[] = ['level' => 'warning', 'label' => ts('Delegated writes also depend on the connected user\'s Entra roles and tenant policy; use Dry Run before Sync.')];
    }
    return $out;
  }

  private function tokenClaims(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) < 2) {
      return [];
    }
    return json_decode(CRM_Utils_String::base64UrlDecode($parts[1]), TRUE) ?: [];
  }

  private function paged(string $path, array $headers = [], array $sprintf = []): array {
    if ($sprintf) {
      $path = vsprintf($path, array_map('rawurlencode', $sprintf));
    }
    $out = [];
    do {
      $page = $this->request('GET', $path, NULL, $headers);
      $out = array_merge($out, $page['value'] ?? []);
      $path = $page['@odata.nextLink'] ?? NULL;
      if ($path && !str_starts_with($path, $this->base . '/')) {
        throw new CRM_Core_Exception(ts('Microsoft Graph returned an unexpected pagination URL.'));
      }
    } while ($path);
    return $out;
  }

  private function request(string $method, string $path, ?array $body = NULL, array $extraHeaders = []): array {
    $url = str_starts_with($path, 'https://') ? $path : $this->base . $path;
    if (!str_starts_with($url, $this->base . '/') && $url !== $this->base) {
      throw new CRM_Core_Exception(ts('Refusing a Microsoft Graph request outside the configured Graph endpoint.'));
    }
    $options = ['headers' => $extraHeaders + ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json']];
    if ($body !== NULL) {
      $options['json'] = $body;
    }
    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (GuzzleHttp\Exception\RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
      $retry = $e->hasResponse() ? $e->getResponse()->getHeaderLine('Retry-After') : '';
      $retryAfter = is_numeric($retry) ? max(1, (int) $retry) : (($retry && strtotime($retry)) ? max(1, strtotime($retry) - time()) : NULL);
      $detail = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
      $decoded = json_decode($detail, TRUE);
      throw new CRM_M365GroupSync_GraphException('Microsoft Graph request failed: ' . ($decoded['error']['message'] ?? $detail), $status, $retryAfter);
    }
    $content = (string) $response->getBody();
    return $content === '' ? [] : (json_decode($content, TRUE) ?: []);
  }

  private function token(): string {
    if ($this->tokenCache !== NULL) {
      return $this->tokenCache;
    }
    $auth = new CRM_M365GroupSync_Service_Auth();
    return $this->tokenCache = (Civi::settings()->get('m365_group_sync_auth_method') ?: 'delegated') === 'delegated'
      ? $auth->accessToken()
      : $auth->applicationToken();
  }
}
