<div class="crm-block crm-content-block">
  <p><a class="button" href="{$adminUrl|escape}">« {ts}Back to Microsoft 365 Group Sync{/ts}</a></p>
  <h3>{ts}Recent Runs{/ts}</h3>
  <table class="selector"><thead><tr><th>{ts}Started{/ts}</th><th>{ts}Group{/ts}</th><th>{ts}Mode / Source{/ts}</th><th>{ts}Status / Phase{/ts}</th><th>{ts}Progress{/ts}</th><th>{ts}Contacts{/ts}</th><th>{ts}Unique Emails{/ts}</th><th>{ts}Added / Removed{/ts}</th><th>{ts}Errors{/ts}</th></tr></thead><tbody>
    {foreach from=$runs item=r}<tr><td>{$r.started_date|escape}</td><td>{$r.group_title|escape}</td><td>{$r.mode|escape}<br><span class="description">{$r.source|escape}</span></td><td>{$r.status|escape}<br><span class="description">{$r.phase|escape}</span>{if $r.resume_url}<br><a class="button" href="{$r.resume_url|escape}">{ts}Resume progress{/ts}</a>{/if}</td><td>{$r.processed_items} / {$r.total_items}</td><td>{$r.summary.qualifying_contacts|default:'-'}</td><td>{$r.summary.unique_emails|default:'-'}</td><td>{$r.summary.added|default:0} / {$r.summary.removed|default:0}</td><td>{$r.error_items}</td></tr>
    {foreachelse}<tr><td colspan="9">{ts}No synchronization runs have been recorded.{/ts}</td></tr>{/foreach}
  </tbody></table>
  {if $runPager.pages gt 1}
    <nav class="crm-m365-pager" aria-label="{ts}Recent Runs pages{/ts}">
      {if $runPager.previous}<a class="button" href="{$runPager.previous|escape}">‹ {ts}Previous{/ts}</a>{/if}
      {foreach from=$runPager.links key=pageNumber item=pageUrl}
        {if $pageNumber eq $runPager.current}<strong class="crm-m365-current-page">{$pageNumber}</strong>{else}<a class="button" href="{$pageUrl|escape}">{$pageNumber}</a>{/if}
      {/foreach}
      {if $runPager.next}<a class="button" href="{$runPager.next|escape}">{ts}Next{/ts} ›</a>{/if}
      <span class="description">{$runPager.total} {ts}runs{/ts}</span>
    </nav>
  {/if}
  <h3>{ts}Recent Actions and Events{/ts}</h3>
  <form method="get" action="{$logUrl|escape}" class="crm-m365-log-filters">
    <input type="hidden" name="reset" value="1">
    <input type="hidden" name="run_page" value="{$runPager.current}">
    {if $fixedGroup}<input type="hidden" name="gid" value="{$fixedGroup}">{/if}
    {if !$fixedGroup}
      <label>{ts}Group{/ts}
        <select name="event_group"><option value="">{ts}All groups{/ts}</option>{foreach from=$eventGroups key=value item=label}<option value="{$value}"{if $eventFilters.group eq $value} selected{/if}>{$label|escape}</option>{/foreach}</select>
      </label>
    {/if}
    <label>{ts}Action{/ts}
      <select name="event_action"><option value="">{ts}All actions{/ts}</option>{foreach from=$eventActions key=value item=label}<option value="{$value|escape}"{if $eventFilters.action eq $value} selected{/if}>{$label|escape}</option>{/foreach}</select>
    </label>
    <label>{ts}Result{/ts}
      <select name="event_result"><option value="">{ts}All results{/ts}</option>{foreach from=$eventResults key=value item=label}<option value="{$value|escape}"{if $eventFilters.result eq $value} selected{/if}>{$label|escape}</option>{/foreach}</select>
    </label>
    <label>{ts}Search{/ts} <input type="search" name="event_search" value="{$eventFilters.search|escape}" placeholder="{ts}Contact, email, or message{/ts}"></label>
    <button type="submit" class="button crm-form-submit"><span class="crm-i fa-filter" aria-hidden="true"></span> {ts}Apply filters{/ts}</button>
    <a class="button" href="{$clearLogUrl|escape}">{ts}Clear{/ts}</a>
  </form>
  <p class="description">{ts 1=$eventRangeStart 2=$eventRangeEnd 3=$eventTotal}Showing %1–%2 of %3 events.{/ts}</p>
  <table class="selector"><thead><tr><th>{ts}Date{/ts}</th><th>{ts}Group{/ts}</th><th>{ts}Contact{/ts}</th><th>{ts}Email{/ts}</th><th>{ts}Action{/ts}</th><th>{ts}Result{/ts}</th><th>{ts}Message{/ts}</th></tr></thead><tbody>
    {foreach from=$logs item=l}<tr><td>{$l.created_date|escape}</td><td>{$l.group_title|escape}</td><td>{$l.civicrm_contact_id|default:'-'|escape}</td><td>{$l.effective_email|default:'-'|escape}</td><td>{$l.action|escape}</td><td>{$l.result|escape}</td><td>{$l.message|escape}</td></tr>
    {foreachelse}<tr><td colspan="7">{ts}No individual synchronization events have been recorded.{/ts}</td></tr>{/foreach}
  </tbody></table>
  {if $eventPager.pages gt 1}
    <nav class="crm-m365-pager" aria-label="{ts}Recent Actions and Events pages{/ts}">
      {if $eventPager.previous}<a class="button" href="{$eventPager.previous|escape}">‹ {ts}Previous{/ts}</a>{/if}
      {foreach from=$eventPager.links key=pageNumber item=pageUrl}
        {if $pageNumber eq $eventPager.current}<strong class="crm-m365-current-page">{$pageNumber}</strong>{else}<a class="button" href="{$pageUrl|escape}">{$pageNumber}</a>{/if}
      {/foreach}
      {if $eventPager.next}<a class="button" href="{$eventPager.next|escape}">{ts}Next{/ts} ›</a>{/if}
    </nav>
  {/if}
</div>

{literal}<style>
.crm-m365-log-filters{display:flex;align-items:end;flex-wrap:wrap;gap:.75rem;margin:.75rem 0;padding:.75rem;background:#f4f4f4}.crm-m365-log-filters label{display:flex;flex-direction:column;gap:.2rem;font-weight:600}.crm-m365-log-filters select,.crm-m365-log-filters input{min-width:10rem}.crm-m365-pager{display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;margin:.75rem 0}.crm-m365-current-page{display:inline-block;padding:.35rem .7rem;border-radius:.2rem;background:#0566a3;color:#fff}
</style>{/literal}
