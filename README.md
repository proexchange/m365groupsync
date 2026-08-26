# Microsoft 365 Group Sync

One-way reconciliation from CiviCRM Groups (including Smart Groups) to existing Microsoft 365 Groups. CiviCRM is authoritative; owners and all Microsoft 365 Group configuration are preserved.

## Install the extension

1. Enable **Microsoft 365 Group Sync** in CiviCRM's **Manage Extensions** page.
2. Open **Administer → System Settings → Microsoft 365 Group Sync**.
3. Choose one authentication method and follow the corresponding guide below.
4. Leave automatic synchronization disabled until authentication, mapping, Compare, and Dry Run have been validated.

Upgrading from 1.2.0 to 1.2.1 intentionally disconnects an existing delegated OAuth session once. Sign in with Microsoft again so the replacement tokens can be bound to the configured Tenant ID and Application ID. Application-credential connections must run **Test Connection** once after this upgrade for the same reason.

## Authentication overview

Only one authentication method is active at a time. Both methods require a Microsoft Entra app registration, Microsoft Graph permission grants, tenant administrator consent, and a client secret.

### Sign in with Microsoft (delegated OAuth)

Use this method when Graph operations should run as a designated Microsoft administrator. It provides an interactive Microsoft sign-in and stores an encrypted refresh token so scheduled jobs can continue after the browser session ends.

#### Configure Microsoft Entra

1. In the Microsoft Entra admin center, open **Identity → Applications → App registrations → New registration**.
2. Create a single-tenant registration for this CiviCRM installation.
3. Open **Authentication → Add a platform → Web**.
4. Copy the **Delegated redirect URI** displayed on the CiviCRM configuration page and register it exactly. The scheme, hostname, path, and port must match.
5. Open **Certificates & secrets → Client secrets**, create a client secret, and copy the complete entry from the **Value** column—not the **Secret ID**. Microsoft displays the full Value only immediately after creation, so copy and store it securely before leaving the page.
6. Open **API permissions → Add a permission → Microsoft Graph → Delegated permissions** and add:
   - `User.Read`
   - `Group.Read.All`
   - `GroupMember.ReadWrite.All`
   - `User.Read.All`
   - `User.Invite.All`
7. Select **Grant admin consent** for the tenant. The extension requests `openid`, `profile`, and `offline_access` during authorization; `offline_access` enables refresh tokens.
8. Ensure the Microsoft user who will connect has an Entra role and tenant policy that permit updating group membership and inviting guest users. Graph permission consent does not replace the connected user's own authorization.

#### Connect CiviCRM

1. Select **Sign in with Microsoft** on the extension configuration page.
2. Enter the tenant GUID or verified tenant domain, the Application (client) ID, and the client secret's complete **Value**. Do not enter its **Secret ID**.
3. Click **Sign in with Microsoft** and complete the Microsoft consent/sign-in flow as the designated administrator.
4. Confirm that CiviCRM displays the connected tenant and user.
5. Click **Test Connection** and resolve every error before mapping or syncing groups.

Use **Reconnect** (the sign-in button while connected) when consent, the connected account, or authorization must change. **Disconnect** removes delegated tokens but preserves mappings and sync logs.

### Application Credentials (app-only OAuth)
*** This has NOT been tested thoroughly, proceed carefully ***

Use this method for an unattended service integration that does not depend on a Microsoft user's session or privileges. Graph operations run as the Entra application itself.

#### Configure Microsoft Entra

1. In the Microsoft Entra admin center, open **Identity → Applications → App registrations → New registration**.
2. Create a single-tenant registration for this CiviCRM installation.
3. A redirect URI is not required for the application-credentials flow.
4. Open **Certificates & secrets → Client secrets**, create a client secret, and copy the complete entry from the **Value** column—not the **Secret ID**. Microsoft displays the full Value only immediately after creation, so copy and store it securely before leaving the page.
5. Open **API permissions → Add a permission → Microsoft Graph → Application permissions** and add:
   - `Group.Read.All`
   - `GroupMember.ReadWrite.All`
   - `User.Read.All`
   - `User.Invite.All`
6. Select **Grant admin consent** for the tenant. Application permissions do not become usable until tenant administrator consent is granted.
7. Confirm that tenant guest-invitation policy allows applications with `User.Invite.All` to invite guests.

#### Connect CiviCRM

1. Select **Application Credentials** on the extension configuration page.
2. Enter the tenant GUID or verified tenant domain, the Application (client) ID, and the client secret's complete **Value**. Do not enter its **Secret ID**.
3. Click **Test Connection**. A successful test validates the credentials, reads Microsoft 365 Groups, and checks the token's Graph application roles.
4. Confirm that CiviCRM displays the connected tenant and **Application credentials** as the method.
5. Record the client-secret expiration date and rotate the secret before it expires. Entering a replacement secret and successfully testing it does not remove group mappings or logs.

## Switching authentication methods

Selecting a different method or changing the Tenant ID or Application ID cancels active synchronization work and invalidates the existing delegated connection. The replacement method becomes active only after it validates:

- To switch to delegated OAuth, enter its app details and complete **Sign in with Microsoft**.
- To switch to application credentials, enter its app details and complete **Test Connection**.
- Existing CiviCRM-to-Microsoft group mappings and historical logs are preserved.
- Replacing only the client-secret Value invalidates the cached access token so **Test Connection** must validate the new secret instead of reusing the old access token.

Client secrets, access tokens, and refresh tokens are stored with CiviCRM's credential encryption key. A CiviCRM settings override may be used for production secret management.

## Permission and API access

The extension adds **Microsoft 365 Group Sync: administer synchronization**. Grant it to every CiviCRM user who administers mappings or runs synchronization, including the dedicated CiviCRM cron user. All Microsoft 365 Group Sync actions use APIv4: `run`, `start`, `process`, `status`, `cancel`, and `scheduled`.

## Map and synchronize groups

1. As a user with **Microsoft 365 Group Sync: administer synchronization**, edit a CiviCRM Group and select an existing mail-enabled Microsoft 365 (Unified) group in the **Microsoft 365 Group** field. A Microsoft group can be mapped to only one CiviCRM Group.
2. Run **Compare** to review current differences.
3. Run **Dry Run** to resolve identities in Microsoft Graph batches and predict guest creation, additions, and removals without Microsoft writes.
4. Run **Sync Now** only after reviewing the first-sync removal warning. Dry Run and Sync are durable operations: the configuration page displays progress and processes one batch at a time. You may leave the page; an enabled scheduled job resumes queued work.
5. Enable automatic synchronization when manual validation succeeds. The scheduled job checks for queued work whenever CiviCRM cron runs and starts a new automatic reconciliation.

## Queue processing and recovery

- Microsoft Graph requests are grouped into batches of at most 20. A 275-address identity check therefore needs about 14 Graph batch calls instead of 275 serial calls.
- **Compare** is immediate and does not resolve every missing email against the Microsoft directory. **Dry Run** performs those lookups but never invites, adds, or removes anyone.
- Each completed batch is checkpointed in CiviCRM. Temporary Graph throttling and service failures honor `Retry-After` and are retried up to five times.
- Only one active run is allowed for each mapped CiviCRM Group. Smart Group cache rebuilding is limited to the group whose run is being prepared.
- Changing or deleting a mapping requests cancellation of its active run. If a Graph batch is already in progress, the mapping change is refused until that bounded batch finishes; save it again afterward. Workers also revalidate the mapping before every batch.
- Cancelling stops remaining batches and does not undo completed invitations or membership changes. If removals have not started, cancellation prevents them from starting.
- The scheduled job uses the **Always** frequency so normal cron invocations can resume work promptly. Disabling that CiviCRM job stops background processing, but manual runs can still advance while their browser progress panel remains open.

## Uninstall cleanup

Uninstalling the extension permanently removes its local group mappings, synchronization runs and event logs, the scheduled job, all `m365_group_sync_*` settings, and encrypted client secrets and OAuth tokens. 

Uninstall does not alter Microsoft 365 Groups, Entra users or guests. Complete any required Microsoft-side cleanup separately.
