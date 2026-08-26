<div class="crm-block crm-form-block crm-m365-sync-admin">
  <div class="crm-section">
    <div class="content">{$form.enabled.html} {$form.enabled.label}</div>
  </div>
  <div class="crm-section"><div class="label">{$form.automatic_cadence.label}</div><div class="content">{$form.automatic_cadence.html}<div class="description">{ts}Automatic reconciliation is scheduled independently for this CiviCRM domain. The worker still runs each normal cron invocation to resume queued batches and retries.{/ts}</div></div></div>

  {if $legacyResolutionRequired}
    <div class="messages status no-popup"><strong>{ts}Legacy multidomain migration required.{/ts}</strong> {ts}Existing Microsoft 365 sync data has no domain ownership. Confirm that all legacy mappings, runs, logs, and queued items belong to this site’s CiviCRM domain before synchronization can resume.{/ts}<div class="crm-submit-buttons">{$form.claim_legacy.html}</div></div>
  {/if}

  <fieldset>
    <legend>{ts}Microsoft 365 Authentication{/ts}</legend>
    <div class="crm-section"><div class="label">{$form.auth_method.label}</div><div class="content">{$form.auth_method.html}</div></div>
    <div id="crm-m365-delegated-guidance" class="help crm-m365-auth-guidance">
      <p><strong>{ts}Sign in with Microsoft (delegated OAuth){/ts}</strong></p>
      <p>{ts}Runs Microsoft Graph as a designated Microsoft administrator and stores an encrypted refresh token for hourly jobs. Create a single-tenant Entra Web app, register the exact redirect URI shown below, add the delegated Graph permissions, and grant tenant admin consent.{/ts}</p>
      <ol>
        <li>{ts}Enter the Tenant ID, Application ID, and the client secret's complete Value. Do not enter the Secret ID.{/ts}</li>
        <li>{ts}Click “Sign in with Microsoft” and sign in as an administrator permitted to update group membership and invite guests.{/ts}</li>
        <li>{ts}Return here and run “Test Connection” before mapping or synchronizing groups.{/ts}</li>
      </ol>
      <p><strong>{ts}Delegated permissions:{/ts}</strong> <code>User.Read</code>, <code>Group.Read.All</code>, <code>GroupMember.ReadWrite.All</code>, <code>User.Read.All</code>, <code>User.Invite.All</code>. <code>offline_access</code> {ts}is requested during sign-in.{/ts}</p>
    </div>
    <div id="crm-m365-application-guidance" class="help crm-m365-auth-guidance">
      <p><strong>{ts}Application Credentials (app-only OAuth){/ts}</strong></p>
      <p>{ts}Runs Microsoft Graph as the Entra application and is best for an unattended service connection. Create a single-tenant Entra app, add the application Graph permissions, grant tenant admin consent, and create a client secret. No redirect URI or Microsoft user sign-in is required.{/ts}</p>
      <ol>
        <li>{ts}Enter the Tenant ID, Application ID, and the client secret's complete Value. Do not enter the Secret ID.{/ts}</li>
        <li>{ts}Click “Test Connection”; a successful test activates Application Credentials.{/ts}</li>
        <li>{ts}Track the client-secret expiration date and replace it before it expires.{/ts}</li>
      </ol>
      <p><strong>{ts}Application permissions:{/ts}</strong> <code>Group.Read.All</code>, <code>GroupMember.ReadWrite.All</code>, <code>User.Read.All</code>, <code>User.Invite.All</code>.</p>
    </div>
    <div class="crm-section"><div class="label">{$form.tenant_id.label}</div><div class="content">{$form.tenant_id.html}<div class="description">{ts}Enter the Microsoft Entra tenant GUID or a verified tenant domain, such as contoso.onmicrosoft.com.{/ts}</div></div></div>
    <div class="crm-section"><div class="label">{$form.client_id.label}</div><div class="content">{$form.client_id.html}</div></div>
    <div class="crm-section"><div class="label">{$form.client_secret.label}</div><div class="content">{$form.client_secret.html}<div class="description"><strong>{ts}Paste the complete Value from Entra's Client secrets table—not the Secret ID.{/ts}</strong> {ts}The full Value is shown only when the secret is created. Leave this field blank later to keep the existing encrypted credential.{/ts}</div></div></div>
    <div class="crm-section crm-m365-delegated-only"><div class="label">{ts}Delegated redirect URI{/ts}</div><div class="content"><code>{$redirectUri|escape}</code><div class="description">{ts}Register this exact URI as a Web redirect URI in the Entra app registration.{/ts}</div></div></div>

    <div class="crm-m365-connection crm-section">
      <div class="label">{ts}Connection status{/ts}</div>
      <div class="content">
        {if $connectedTenant || $connectedUser}
          <strong class="crm-m365-connection-badge crm-m365-connection-badge-connected">{ts}Connected{/ts}</strong>
          <div class="crm-m365-connection-details">
            {if $connectedTenant}<div>{ts}Tenant:{/ts} {$connectedTenant|escape}</div>{/if}
            {if $connectedUser}<div>{ts}User / method:{/ts} {$connectedUser|escape}</div>{/if}
            {if $lastAuth}<div>{ts}Last successful authentication or refresh:{/ts} {$lastAuth|escape}</div>{/if}
          </div>
        {else}
          <strong class="crm-m365-connection-badge crm-m365-connection-badge-disconnected">{ts}Not connected{/ts}</strong>
        {/if}
      </div>
    </div>
    <div class="crm-section"><div class="content">
      <span class="crm-m365-delegated-only">{$form.connect.html}</span> {$form.test.html}
      {if $hasRefreshToken}<span class="crm-m365-delegated-only">{$form.disconnect.html}</span>{/if}
    </div></div>
  </fieldset>

  {if $validationFindings}
    <h3>{ts}Capability Validator{/ts}</h3>
    <ul class="crm-m365-findings">
      {foreach from=$validationFindings item=finding}
        <li class="crm-m365-{$finding.level|escape}">{if $finding.level eq 'success'}✓{elseif $finding.level eq 'warning'}⚠{else}✗{/if} {$finding.label|escape}</li>
      {/foreach}
    </ul>
  {/if}

  <div class="crm-section"><div class="label">{$form.retention_days.label}</div><div class="content">{$form.retention_days.html}</div></div>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl"}</div>

  <h3>{ts}Group Mapping Dashboard{/ts}</h3>
  <div class="crm-submit-buttons crm-m365-global-actions">{$form.compare_all.html} {$form.dry_run_all.html} {$form.sync_all.html} <a class="button" href="{$logUrl|escape}">{ts}View Log{/ts}</a></div>
  {if $activeOperation}
    <div id="crm-m365-progress" class="help" data-operation="{$activeOperation|escape}">
      <div class="crm-m365-progress-heading"><strong>{ts}Synchronization progress{/ts}</strong> <button type="button" class="button crm-m365-cancel">{ts}Cancel{/ts}</button></div>
      <p class="crm-m365-progress-notice"><strong>{ts}Keep this page open until the Dry Run or Sync completes for immediate processing.{/ts}</strong> {ts}If you navigate away, completed progress is saved, but remaining work will continue only when the Microsoft 365 scheduled job runs or when you return and resume it.{/ts}</p>
      <div class="crm-m365-progress-summary">{ts}Loading queued work…{/ts}</div>
      <div class="crm-m365-progress-runs"></div>
    </div>
  {/if}
  <div class="crm-section crm-m365-dashboard-filter"><div class="label">{$form.mapped_status.label}</div><div class="content">{$form.mapped_status.html}</div></div>
  <table class="selector row-highlight">
    <thead><tr>
      <th>{ts}CiviCRM Group{/ts}</th><th>{ts}Microsoft 365 Group{/ts}</th><th>{ts}Type{/ts}</th>
      <th>{ts}Contacts / Emails{/ts}</th><th>{ts}M365 / Owners{/ts}</th><th>{ts}Status{/ts}</th><th>{ts}Last Run{/ts}</th><th>{ts}Actions{/ts}</th>
    </tr></thead>
    <tbody>
      {foreach from=$mappings item=m}
        {assign var=compareButton value=$m.compare_button}{assign var=dryButton value=$m.dry_run_button}{assign var=syncButton value=$m.sync_button}
        <tr class="crm-m365-row-mapped">
          <td><a href="{$m.edit_url|escape}">{$m.civicrm_group_title|escape}</a> <span class="description">#{$m.civicrm_group_id}</span></td>
          <td>{$m.m365_display_name|default:$m.m365_group_id|escape}{if $m.m365_mail}<br><span class="description">{$m.m365_mail|escape}</span>{/if}</td>
          <td>{$m.group_type|escape}</td>
          <td>{$m.summary.qualifying_contacts|default:'-'} / {$m.summary.unique_emails|default:'-'}</td>
          <td>{$m.summary.m365_managed_members|default:'-'} / {$m.summary.owner_members_excluded|default:'-'}</td>
          <td>{$m.last_status|default:'Sync Pending'|escape}</td><td>{$m.last_sync|default:'-'|escape}</td>
          <td><a href="{$m.edit_url|escape}">{ts}Edit Mapping{/ts}</a> · <a href="{$m.log_url|escape}">{ts}View Log{/ts}</a><br>{$form.$compareButton.html} {$form.$dryButton.html} {$form.$syncButton.html}</td>
        </tr>
      {/foreach}
      {if !$mappings}
        <tr class="crm-m365-row-mapped"><td colspan="8">{ts}No CiviCRM Groups are mapped. Choose “Not Mapped” or “All” to see groups available for mapping.{/ts}</td></tr>
      {/if}
      {foreach from=$unmappedGroups item=g}
        <tr class="disabled crm-m365-row-not-mapped"><td><a href="{$g.edit_url|escape}">{$g.civicrm_group_title|escape}</a> <span class="description">#{$g.civicrm_group_id}</span></td><td>{ts}Not mapped{/ts}</td><td>{$g.group_type|escape}</td><td>-</td><td>-</td><td>{ts}Not Mapped{/ts}</td><td>-</td><td><a href="{$g.edit_url|escape}">{ts}Edit Mapping{/ts}</a></td></tr>
      {/foreach}
    </tbody>
  </table>
  <div class="help warning"><p><strong>{ts}First-sync safety:{/ts}</strong> {ts}Compare and Dry Run are read-only. An actual first sync removes non-owner Microsoft 365 members not represented by the mapped CiviCRM Group. Unmapping is non-destructive; Microsoft group settings and owners are never changed.{/ts}</p></div>
</div>

{literal}<style>
.crm-m365-auth-guidance{margin:1rem 0}.crm-m365-auth-guidance p{margin:.35rem 0}.crm-m365-auth-guidance ol{margin:.5rem 0 .5rem 1.5rem}.crm-m365-connection-badge{display:inline-block;padding:.35rem .7rem;margin:0 0 .75rem;border:1px solid;border-radius:.25rem;font-weight:700;line-height:1.4}.crm-m365-connection-badge-connected{background:#e4f4e7;border-color:#91c99a;color:#185b25}.crm-m365-connection-badge-disconnected{background:#fbe9e9;border-color:#e3a6a6;color:#8a2424}.crm-m365-connection-details{display:grid;clear:both;gap:.25rem}.crm-m365-findings{list-style:none;padding-left:0}.crm-m365-findings li{padding:.35rem .5rem;margin:.2rem 0}.crm-m365-success{color:#27752b}.crm-m365-warning{color:#8a5a00}.crm-m365-error{color:#a51b1b}.crm-m365-global-actions{margin:.75rem 0}.crm-m365-dashboard-filter{margin:.75rem 0}.crm-m365-sync-admin table .crm-form-submit{font-size:.85em;padding:.25rem .5rem;margin:.1rem}.crm-m365-progress-heading{display:flex;justify-content:space-between;align-items:center}.crm-m365-progress-notice{margin:.75rem 0;padding:.65rem .8rem;background:#fff4cf;border-left:4px solid #c58b00}.crm-m365-progress-run{margin:.6rem 0}.crm-m365-progress-track{height:.75rem;background:#d7e1e8;border-radius:.4rem;overflow:hidden}.crm-m365-progress-bar{height:100%;background:#2f7d32;transition:width .2s}.crm-m365-progress-meta{font-size:.9em;margin-top:.2rem}.crm-m365-progress-error .crm-m365-progress-bar{background:#a51b1b}
</style>
<script>
CRM.$(function($) {
  function updateM365AuthGuidance() {
    var delegated = $('#auth_method').val() === 'delegated';
    $('#crm-m365-delegated-guidance').toggle(delegated);
    $('#crm-m365-application-guidance').toggle(!delegated);
    $('.crm-m365-delegated-only').toggle(delegated);
  }
  $('#auth_method').on('change', updateM365AuthGuidance);
  updateM365AuthGuidance();

  function updateMappedStatus() {
    var status = $('#mapped_status').val();
    $('.crm-m365-row-mapped').toggle(status === 'mapped' || status === 'all');
    $('.crm-m365-row-not-mapped').toggle(status === 'not_mapped' || status === 'all');
  }
  $('#mapped_status').on('change', updateMappedStatus);
  updateMappedStatus();

  var $progress = $('#crm-m365-progress');
  if ($progress.length) {
    var operation = $progress.data('operation');
    var stopped = false;
    function apiValue(result) {
      var value = result && result.values !== undefined ? result.values : result;
      return Array.isArray(value) && value.length === 1 ? value[0] : value;
    }
    function render(data) {
      data = apiValue(data) || {};
      var active = parseInt(data.active || 0, 10);
      var waiting = false;
      $progress.find('.crm-m365-progress-summary').text(active ? active + ' group run(s) still active.' : 'Operation complete.');
      var $runs = $progress.find('.crm-m365-progress-runs').empty();
      $.each(data.runs || [], function(_, run) {
        if (run.status === 'retry_wait') waiting = true;
        var total = parseInt(run.total || 0, 10), processed = parseInt(run.processed || 0, 10);
        var percent = total ? Math.min(100, Math.round(processed * 100 / total)) : (run.phase === 'complete' ? 100 : 0);
        var $row = $('<div class="crm-m365-progress-run">').toggleClass('crm-m365-progress-error', parseInt(run.errors || 0, 10) > 0);
        $('<strong>').text(run.group + ' — ' + run.mode).appendTo($row);
        $('<div class="crm-m365-progress-track"><div class="crm-m365-progress-bar"></div></div>').appendTo($row).find('.crm-m365-progress-bar').css('width', percent + '%');
        $('<div class="crm-m365-progress-meta">').text(run.phase + ': ' + processed + ' / ' + total + '; status: ' + run.status + '; errors: ' + run.errors).appendTo($row);
        $runs.append($row);
      });
      if (!active) { stopped = true; $progress.find('.crm-m365-cancel').hide(); }
      return {active: active, waiting: waiting};
    }
    function advance() {
      if (stopped) return;
      CRM.api4('M365GroupSync', 'process', {operationId: operation}).then(function(result) {
        var state = render(result);
        if (state.active) window.setTimeout(advance, state.waiting ? 3000 : 500);
        else window.setTimeout(function(){ window.location.href = window.location.href.replace(/([?&])op=[^&]*&?/, '$1').replace(/[?&]$/, ''); }, 1200);
      }).catch(function(error) {
        stopped = true;
        $progress.find('.crm-m365-progress-summary').text((error && error.error_message) || 'Queue processing failed. The scheduled job can resume this operation.');
      });
    }
    $progress.on('click', '.crm-m365-cancel', function() {
      $(this).prop('disabled', true);
      CRM.api4('M365GroupSync', 'cancel', {operationId: operation}).then(function(result) {
        render(result);
        if (!stopped) window.setTimeout(advance, 250);
      }).catch(function(error) {
        stopped = true;
        $progress.find('.crm-m365-progress-summary').text((error && error.error_message) || 'Cancellation failed. The scheduled job can resume this operation.');
      });
    });
    advance();
  }
});
</script>{/literal}
