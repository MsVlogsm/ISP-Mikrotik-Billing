<x-app-layout>
<div class="container-fluid py-3">
 <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4"><div><h2 class="mb-1">Zabbix Monitoring</h2><p class="text-muted mb-0">Read-only Zabbix API integration for host and problem visibility.</p></div><a href="{{ route('ipilot') }}" class="btn btn-outline-secondary">iPilot Workspace</a></div>
 @if(session('zabbix_message'))<div class="alert alert-success">{{ session('zabbix_message') }}</div>@endif
 @if(session('zabbix_error'))<div class="alert alert-danger">{{ session('zabbix_error') }}</div>@endif
 <div class="alert alert-info">API tokens are encrypted at rest. TLS certificate verification is always enabled. This integration only reads Zabbix hosts and problems; it does not create, update or acknowledge monitoring objects.</div>
 <div class="row g-4">
  <div class="col-xl-4"><div class="card"><div class="card-header fw-semibold">Add Zabbix Server</div><div class="card-body"><form method="post" action="{{ route('ipilot.zabbix.store') }}">@csrf
   <div class="mb-3"><label class="form-label">Server Name</label><input name="name" class="form-control" maxlength="120" required placeholder="Main NOC Zabbix"></div>
   <div class="mb-3"><label class="form-label">API URL</label><input name="api_url" type="url" class="form-control" required placeholder="https://zabbix.example.com"></div>
   <div class="mb-3"><label class="form-label">API Token</label><input name="api_token" type="password" class="form-control" required autocomplete="new-password"></div>
   <button class="btn btn-primary">Save Server</button>
  </form><p class="small text-muted mt-3 mb-0">Use a Zabbix API token with host/problem read permissions only. The token is never returned to the browser after saving.</p></div></div></div>
  <div class="col-xl-8"><div class="row g-3">
   <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Configured Servers</div><div class="fs-3 fw-semibold">{{ $servers->total() }}</div></div></div></div>
   <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Active Servers</div><div class="fs-3 fw-semibold">{{ number_format($activeServers) }}</div></div></div></div>
   <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Active Problems</div><div class="fs-3 fw-semibold">{{ number_format($activeProblems) }}</div></div></div></div>
  </div>
  <div class="card mt-3"><div class="card-header fw-semibold">Configured Zabbix Servers</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Name / URL</th><th>Version</th><th>Hosts</th><th>Problems</th><th>Last Check</th><th>Actions</th></tr></thead><tbody>
   @forelse($servers as $server)<tr><td><strong>{{ $server->name }}</strong><div class="small text-muted text-break">{{ $server->api_url }}</div><span class="badge {{ $server->is_active?'bg-success':'bg-secondary' }}">{{ $server->is_active?'Active':'Paused' }}</span></td><td>{{ $server->api_version ?? 'Not tested' }}</td><td>{{ $server->host_count ?? '—' }}</td><td>{{ $server->problem_count ?? '—' }}</td><td>{{ $server->last_checked_at?->diffForHumans() ?? 'Never' }}@if($server->last_error)<div class="small text-danger">{{ $server->last_error }}</div>@endif</td><td><div class="d-flex flex-wrap gap-1"><form method="post" action="{{ route('ipilot.zabbix.test',$server) }}">@csrf<button class="btn btn-sm btn-outline-primary">Test</button></form><button type="button" class="btn btn-sm btn-outline-info" data-hosts-url="{{ route('ipilot.zabbix.hosts',$server) }}">Hosts</button><button type="button" class="btn btn-sm btn-outline-info" data-problems-url="{{ route('ipilot.zabbix.problems',$server) }}">Problems</button><form method="post" action="{{ route('ipilot.zabbix.toggle',$server) }}">@csrf @method('patch')<button class="btn btn-sm btn-outline-secondary">{{ $server->is_active?'Pause':'Enable' }}</button></form><form method="post" action="{{ route('ipilot.zabbix.delete',$server) }}" onsubmit="return confirm('Delete Zabbix server and encrypted token?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td></tr>
   @empty<tr><td colspan="6" class="text-center text-muted py-4">No Zabbix servers configured. Add an HTTPS API endpoint and read-only token, then run Test.</td></tr>@endforelse
   </tbody></table></div><div class="card-footer">{{ $servers->links() }}</div></div>
  </div>
 </div>
 <div class="card mt-4 d-none" id="zabbix-inspection-panel"><div class="card-header d-flex justify-content-between"><strong id="zabbix-inspection-title">Zabbix Inventory</strong><button class="btn btn-sm btn-outline-secondary" id="zabbix-inspection-close">Close</button></div><div class="table-responsive"><table class="table mb-0"><thead id="zabbix-inspection-head"></thead><tbody id="zabbix-inspection-body"></tbody></table></div></div>
</div>
<script>
(() => {
 const panel=document.getElementById('zabbix-inspection-panel');
 const title=document.getElementById('zabbix-inspection-title');
 const head=document.getElementById('zabbix-inspection-head');
 const body=document.getElementById('zabbix-inspection-body');
 const close=document.getElementById('zabbix-inspection-close');
 if(!panel||panel.dataset.ready==='1')return;
 panel.dataset.ready='1';
 const cell=(row,value,tag='td')=>{const el=document.createElement(tag);el.textContent=String(value??'—');row.appendChild(el);return el;};
 const showError=(message)=>{head.replaceChildren();body.replaceChildren();const row=document.createElement('tr');const td=cell(row,message);td.className='text-danger';body.appendChild(row);};
 async function load(url,kind){
  panel.classList.remove('d-none');
  title.textContent=kind==='hosts'?'Zabbix Hosts':'Recent Zabbix Problems';
  head.replaceChildren();body.replaceChildren();
  const columns=kind==='hosts'?['Host','Address','Groups','Status','Host ID']:['Problem','Severity','Event ID','Time'];
  const headerRow=document.createElement('tr');columns.forEach(v=>cell(headerRow,v,'th'));head.appendChild(headerRow);
  const loading=document.createElement('tr');const loadingCell=cell(loading,'Loading…');loadingCell.colSpan=columns.length;body.appendChild(loading);
  try{
   const response=await fetch(url,{headers:{Accept:'application/json'}});
   const result=await response.json();
   if(!response.ok||!result.success)throw new Error(result.message||'Could not load Zabbix data.');
   body.replaceChildren();
   const rows=Array.isArray(result.data)?result.data:[];
   rows.forEach(item=>{
    const row=document.createElement('tr');
    if(kind==='hosts'){
     cell(row,item.name||item.host);
     cell(row,item.address);
     cell(row,Array.isArray(item.groups)?item.groups.join(', '):'—');
     const status=cell(row,item.status);status.className=item.status==='enabled'?'text-success':'text-muted';
     cell(row,item.hostid);
    }else{
     cell(row,item.name);
     cell(row,item.severity);
     cell(row,item.eventid);
     cell(row,item.clock?new Date(Number(item.clock)*1000).toLocaleString():'—');
    }
    body.appendChild(row);
   });
   if(rows.length===0){const row=document.createElement('tr');const td=cell(row,kind==='hosts'?'No hosts returned.':'No unresolved problems returned.');td.colSpan=columns.length;td.className='text-muted text-center';body.appendChild(row);}
  }catch(error){showError(error.message||'Zabbix data is unavailable.');}
  panel.scrollIntoView({behavior:'smooth',block:'nearest'});
 }
 document.querySelectorAll('[data-hosts-url]').forEach(button=>button.addEventListener('click',()=>load(button.dataset.hostsUrl,'hosts')));
 document.querySelectorAll('[data-problems-url]').forEach(button=>button.addEventListener('click',()=>load(button.dataset.problemsUrl,'problems')));
 close?.addEventListener('click',()=>panel.classList.add('d-none'));
})();
</script>
</div>
</x-app-layout>
