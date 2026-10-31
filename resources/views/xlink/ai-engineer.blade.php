<x-app-layout>
<style>
.ai-engineer{max-width:1180px;margin:0 auto}.ai-hero{background:linear-gradient(135deg,#172b4d,#276ef1);color:#fff;border-radius:18px;padding:28px;box-shadow:0 12px 30px rgba(31,58,99,.15)}.ai-card{background:#fff;border:1px solid #e5eaf2;border-radius:16px;box-shadow:0 8px 24px rgba(31,58,99,.07)}.ai-chat{min-height:260px}.ai-bubble{background:#f5f8fc;border-radius:14px;padding:16px}.ai-chip{border:1px solid #dbe4f0;background:#fff;border-radius:999px;padding:7px 12px;font-size:13px}.ai-result{border-left:4px solid #276ef1}.ai-result.critical{border-left-color:#dc3545}.ai-result.warning{border-left-color:#f0ad4e}.ai-muted{color:#718096}.ai-actions button{min-width:105px}.ai-readonly{font-size:12px;color:#617083}
</style>
<div class="container-fluid py-4 ai-engineer">
 <div class="ai-hero mb-4"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="small opacity-75 mb-1">BengalStack</div><h1 class="h3 mb-2">এআই ইঞ্জিনিয়ার</h1><p class="mb-0 opacity-90">গ্রাহক, ডিভাইস, বিলিং ও ONU mapping যাচাই করুন। শুধু অনন্য exact ONU MAC ↔ PPPoE caller MAC মিল থাকলে ম্যাপিং সেভ করা যাবে; OLT configuration পরিবর্তন হবে না।</p></div><span class="badge bg-light text-primary rounded-pill px-3 py-2">সীমিত mapping permission</span></div></div>
 <div class="row g-3 mb-4">
 @foreach([['label'=>'গ্রাহকs','value'=>$overview['customers']['total'],'sub'=>$overview['customers']['active'].' active'],['label'=>'Pending / Disabled','value'=>$overview['customers']['pending']+$overview['customers']['disabled'],'sub'=>$overview['customers']['pending'].' pending · '.$overview['customers']['disabled'].' disabled'],['label'=>'Routers','value'=>$overview['routers']['total'],'sub'=>$overview['routers']['connected'].' connected'],['label'=>'OLT/ONU mappings','value'=>$overview['olt_onu']['mapped'],'sub'=>$overview['olt_onu']['mapped'] ? 'mapped customers' : 'no mapping data'],['label'=>'Support tickets','value'=>$overview['support']['tickets'],'sub'=>'recorded tickets']] as $card)
 <div class="col-md-6 col-xl"><div class="ai-card p-3 h-100"><div class="small ai-muted">{{ $card['label'] }}</div><div class="h3 mb-1 mt-1">{{ $card['value'] }}</div><div class="small ai-muted">{{ $card['sub'] }}</div></div></div>
 @endforeach
</div> <div class="row g-3 mb-4">
  @php($n=$overview['network'] ?? [])
  @php($inc=$overview['incidents'] ?? [])
  @foreach([
   ['label'=>'Network Devices','value'=>$n['device_total']??0,'sub'=>($n['olt_total']??0).' OLT'],
   ['label'=>'OLT Health','value'=>($n['olt_online']??0),'sub'=>($n['olt_offline']??0).' offline'],
   ['label'=>'ONU Online','value'=>($n['onu_online']??0),'sub'=>($n['onu_offline']??0).' offline'],
   ['label'=>'Affected গ্রাহকs','value'=>($n['affected_customers']??0),'sub'=>'mapped ONU offline/LOS'],
   ['label'=>'Open Incidents','value'=>($inc['open']??0),'sub'=>($inc['critical']??0).' critical · '.($inc['warning']??0).' warning'],
   ['label'=>'Unmapped ONU','value'=>($n['unmapped_onu']??0),'sub'=>'needs review'],
  ] as $card)
   <div class="col-6 col-xl-2"><div class="ai-card p-3 h-100"><div class="small ai-muted">{{ $card['label'] }}</div><div class="h3 mb-1 mt-1">{{ $card['value'] }}</div><div class="small ai-muted">{{ $card['sub'] }}</div></div></div>
  @endforeach
 </div>
 <div class="row g-3 mb-4">
  <div class="col-xl-7"><div class="ai-card p-4 h-100">
   <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="bi bi-activity me-1"></i> স্বয়ংক্রিয় নেটওয়ার্ক সারসংক্ষেপ</h2><span class="badge text-bg-{{ ($inc['status']??'healthy')==='critical'?'danger':(($inc['status']??'healthy')==='attention'?'warning':'success') }}">{{ strtoupper($inc['status']??'healthy') }}</span></div>
   <div class="row g-2 small">
    <div class="col-md-6"><div class="border rounded p-2">Routers: <strong>{{ $n['router_online']??0 }}/{{ $n['router_total']??0 }}</strong> connected</div></div>
    <div class="col-md-6"><div class="border rounded p-2">OLT: <strong>{{ $n['olt_online']??0 }}/{{ $n['olt_total']??0 }}</strong> online</div></div>
    <div class="col-md-6"><div class="border rounded p-2">ONU: <strong>{{ $n['onu_online']??0 }}/{{ $n['onu_total']??0 }}</strong> online</div></div>
    <div class="col-md-6"><div class="border rounded p-2">Affected: <strong>{{ $n['affected_customers']??0 }}</strong> customer estimate</div></div>
   </div>
   <div class="mt-3 small ai-muted">তৈরি হয়েছে {{ $dailySummary['generated_at'] ?? now()->toIso8601String() }} · শুধু-পাঠযোগ্য</div>
  </div></div>
  <div class="col-xl-5"><div class="ai-card p-4 h-100">
   <h2 class="h5 mb-3"><i class="bi bi-exclamation-triangle me-1"></i> সক্রিয় ঘটনা</h2>
   @forelse(($inc['latest']??[]) as $event)
    <div class="border rounded p-2 mb-2 small"><div class="fw-semibold">{{ $event['title'] ?? 'Network event' }}</div><div class="ai-muted">{{ $event['severity'] ?? 'warning' }} · {{ $event['status'] ?? 'open' }} · {{ $event['occurrences'] ?? 1 }} occurrence(s)</div></div>
   @empty <div class="ai-muted small">কোনো খোলা নেটওয়ার্ক ঘটনা পাওয়া যায়নি।</div>
   @endforelse
  </div></div>
 </div>
 <div class="ai-card p-4 mb-4">
  @php($trend=$dailySummary['trend'] ?? [])
  <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="bi bi-graph-up me-1"></i> গত ২৪ ঘণ্টার নেটওয়ার্ক প্রবণতা</h2><span class="badge text-bg-primary">{{ $trend['total_events'] ?? 0 }} ঘটনা</span></div>
  <div class="row g-2 mb-3">
   <div class="col-4"><div class="border rounded p-2 text-center"><div class="small ai-muted">গুরুতর</div><strong>{{ $trend['critical'] ?? 0 }}</strong></div></div>
   <div class="col-4"><div class="border rounded p-2 text-center"><div class="small ai-muted">সতর্কতা</div><strong>{{ $trend['warning'] ?? 0 }}</strong></div></div>
   <div class="col-4"><div class="border rounded p-2 text-center"><div class="small ai-muted">মোট ঘটনা</div><strong>{{ $trend['total_events'] ?? 0 }}</strong></div></div>
  </div>
  <div class="d-flex align-items-end gap-1" style="height:90px">
   @foreach(($trend['hours'] ?? []) as $h)
    @php($bar=max(3,min(80,($h['events'] ?? 0)*8)))
    <div class="flex-fill text-center" title="{{ $h['hour'] }} · {{ $h['events'] }} ঘটনা"><div class="bg-primary rounded-top" style="height:{{ $bar }}px"></div></div>
   @endforeach
  </div>
  <div class="d-flex justify-content-between small ai-muted mt-1"><span>২৪ ঘণ্টা আগে</span><span>বর্তমান</span></div>
  @if(count($trend['top_titles'] ?? []))
   <div class="mt-3"><div class="small fw-semibold mb-2">বারবার হওয়া ঘটনা</div>
    @foreach($trend['top_titles'] as $item)<span class="badge text-bg-light border me-1 mb-1">{{ $item['title'] }} · {{ $item['count'] }}</span>@endforeach
   </div>
  @endif
  <div class="ai-readonly mt-2">এটি গত ২৪ ঘণ্টার তথ্যভিত্তিক প্রবণতা; কোনো নেটওয়ার্ক পরিবর্তন করা হয়নি।</div>
 </div>

 <div class="ai-card p-4 mb-4">
  @php($audit=$billingAudit ?? [])
  @php($auditSummary=$audit['summary'] ?? [])
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
   <div><h2 class="h5 mb-1"><i class="bi bi-receipt me-1"></i> Billing &amp; Payment Audit</h2><div class="small ai-muted">Read-only consistency review · {{ $audit['month'] ?? now()->format('F Y') }}</div></div>
   @if($audit['available'] ?? false)
    <span class="badge text-bg-{{ ($audit['issues_total'] ?? 0)>0 ? 'warning' : 'success' }}">{{ $audit['issues_total'] ?? 0 }} review item(s)</span>
   @else
    <span class="badge text-bg-secondary">Unavailable</span>
   @endif
  </div>
  @if(!($audit['available'] ?? false))
   <div class="alert alert-warning mb-0">{{ $audit['message'] ?? 'Billing audit data is unavailable.' }}</div>
  @else
   <div class="row g-2 mb-3">
    @foreach([
      ['label'=>'Billing records','value'=>$auditSummary['billing_records']??0],
      ['label'=>'Paid mismatch','value'=>$auditSummary['payment_mismatches']??0],
      ['label'=>'Active customers missing billing','value'=>$auditSummary['missing_active_billing']??0],
      ['label'=>'Orphan billing','value'=>$auditSummary['orphan_billing']??0],
      ['label'=>'Orphan collection records','value'=>$auditSummary['orphan_collections']??0],
      ['label'=>'Pending collections','value'=>$auditSummary['pending_collections_current_month']??0],
    ] as $card)
     <div class="col-6 col-md-4 col-xl-2"><div class="border rounded p-3 h-100"><div class="small ai-muted">{{ $card['label'] }}</div><div class="h4 mb-0 mt-1">{{ $card['value'] }}</div></div></div>
    @endforeach
   </div>
   @if(count($audit['findings'] ?? []))
    <div class="table-responsive"><table class="table table-sm align-middle mb-2">
     <thead><tr><th>Severity</th><th>Customer ID</th><th>Finding</th><th>Evidence</th></tr></thead><tbody>
     @foreach($audit['findings'] as $finding)
      <tr>
       <td><span class="badge text-bg-{{ ($finding['severity']??'info')==='critical'?'danger':(($finding['severity']??'info')==='warning'?'warning':'secondary') }}">{{ strtoupper($finding['severity'] ?? 'info') }}</span></td>
       <td class="text-nowrap">{{ $finding['customer_id'] ?? '—' }}@if(!empty($finding['customer_name']))<div class="small ai-muted">{{ $finding['customer_name'] }}</div>@endif</td>
       <td>{{ $finding['details'] ?? 'Review required.' }}</td>
       <td class="small">{{ $finding['evidence'] ?? 'No additional evidence.' }}</td>
      </tr>
     @endforeach
     </tbody></table></div>
   @else
    <div class="small ai-muted">এই মুহূর্তে নির্দিষ্ট কোনো billing/payment mismatch পাওয়া যায়নি। এটি শুধু বর্তমান তথ্যের audit; বাহ্যিক payment provider-এ settlement নিশ্চিত করে না।</div>
   @endif
   <div class="ai-readonly mt-2"><i class="bi bi-shield-check me-1"></i>{{ $audit['note'] ?? 'Read-only audit. No billing or payment records are changed.' }} Findings are review candidates, not automatic corrections.</div>
  @endif
 </div>

 <div class="ai-card p-4 mb-4">
  @php($onuAudit=$onuMappingAudit ?? [])
  @php($onuSummary=$onuAudit['summary'] ?? [])
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
   <div><h2 class="h5 mb-1"><i class="bi bi-diagram-3 me-1"></i> ONU Mapping &amp; Optical Power Audit</h2><div class="small ai-muted">Identity consistency · optical data freshness · read-only</div></div>
   @if($onuAudit['available'] ?? false)
    <span class="badge text-bg-{{ (($onuSummary['ppp_link_mismatches']??0)+($onuSummary['duplicate_mac_groups']??0))>0 ? 'warning' : 'success' }}">{{ $onuAudit['findings_total'] ?? 0 }} review finding(s)</span>
   @else
    <span class="badge text-bg-secondary">Unavailable</span>
   @endif
  </div>
  @if(!($onuAudit['available'] ?? false))
   <div class="alert alert-warning mb-0">{{ $onuAudit['message'] ?? 'ONU mapping audit data is unavailable.' }}</div>
  @else
   <div class="row g-2 mb-3">
    @foreach([
      ['label'=>'Total mappings','value'=>$onuSummary['total_mappings']??0],
      ['label'=>'Linked mappings','value'=>$onuSummary['linked_mappings']??0],
      ['label'=>'Unmapped ONU','value'=>$onuSummary['unmapped']??0],
      ['label'=>'Missing ONU identity','value'=>$onuSummary['missing_identity_records']??0],
      ['label'=>'Invalid ONU MAC','value'=>$onuSummary['invalid_mac_records']??0],
      ['label'=>'PPPoE link mismatches','value'=>$onuSummary['ppp_link_mismatches']??0],
      ['label'=>'Duplicate MAC groups','value'=>$onuSummary['duplicate_mac_groups']??0],
      ['label'=>'Stale optical readings','value'=>$onuSummary['optical_stale']??0],
      ['label'=>'Unknown optical time','value'=>$onuSummary['optical_unknown_timestamp']??0],
      ['label'=>'Missing optical readings','value'=>$onuSummary['optical_missing_reading']??0],
      ['label'=>'Stale online status','value'=>$onuSummary['stale_online_status']??0],
    ] as $card)
     <div class="col-6 col-md-4 col-xl-2"><div class="border rounded p-3 h-100"><div class="small ai-muted">{{ $card['label'] }}</div><div class="h4 mb-0 mt-1">{{ $card['value'] }}</div></div></div>
    @endforeach
   </div>
   @if(count($onuAudit['findings'] ?? []))
    <div class="table-responsive"><table class="table table-sm align-middle mb-2">
     <thead><tr><th>Severity</th><th>ONU / Customer</th><th>Finding</th><th>Evidence</th></tr></thead><tbody>
     @foreach($onuAudit['findings'] as $finding)
      <tr>
       <td><span class="badge text-bg-{{ ($finding['severity']??'info')==='critical'?'danger':(($finding['severity']??'info')==='warning'?'warning':'secondary') }}">{{ strtoupper($finding['severity'] ?? 'info') }}</span></td>
       <td class="text-nowrap">{{ $finding['olt'] ?? '—' }}<div class="small ai-muted">PON {{ $finding['pon'] ?? '—' }} · ONU {{ $finding['onu_id'] ?? '—' }}</div><div class="small ai-muted">{{ $finding['customer_id'] ?: 'No customer linked' }}</div></td>
       <td>{{ $finding['details'] ?? 'Review required.' }}</td>
       <td class="small">{{ $finding['evidence'] ?? 'No additional evidence.' }}</td>
      </tr>
     @endforeach
     </tbody></table></div>
   @else
    <div class="small ai-muted">এই মুহূর্তে identity বা optical freshness review finding নেই।</div>
   @endif
   <div class="ai-readonly mt-2"><i class="bi bi-shield-check me-1"></i>{{ $onuAudit['note'] ?? 'Read-only audit; no mapping changed.' }}</div>
  @endif
 </div>

 <div class="ai-card p-4 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="bi bi-diagram-3 me-1"></i> পর্যালোচনা প্রয়োজন · ম্যাপ না হওয়া ONU</h2><span class="badge text-bg-warning">{{ count($unmappedOnus) }}</span></div>
  @if(count($unmappedOnus))
   <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>OLT</th><th>ONU</th><th>MAC</th><th>PON</th><th>Status</th><th>RX/TX</th><th>Reason</th></tr></thead><tbody>
   @foreach($unmappedOnus as $u)<tr><td>{{ $u['olt'] ?? '—' }}</td><td>{{ $u['onu_id'] ?? '—' }}</td><td>{{ $u['mac'] ?? '—' }}</td><td>{{ $u['pon'] ?? '—' }}</td><td>{{ $u['status'] ?? 'অজানা' }}</td><td>{{ $u['rx'] ?? '—' }} / {{ $u['tx'] ?? '—' }}</td><td>{{ $u['reason'] ?? 'ম্যানুয়াল পর্যালোচনা প্রয়োজন' }}</td></tr>@endforeach
   </tbody></table></div>
  @else <div class="ai-muted small">বর্তমানে পর্যালোচনার জন্য কোনো ম্যাপ না হওয়া ONU নেই।</div>@endif
 </div>

 <div class="row g-3 mb-4">
  <div class="col-xl-7"><div class="ai-card p-4 h-100">
   <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="bi bi-diagram-3 me-1"></i> একই আপস্ট্রিম পথের প্রভাব</h2><span class="badge text-bg-info">{{ count($upstreamCorrelation['groups'] ?? []) }} পথ</span></div>
   @forelse(($upstreamCorrelation['groups'] ?? []) as $g)
    <div class="border rounded p-2 mb-2 small">
      <div class="fw-semibold">OLT {{ $g['olt_device_id'] ?? 'অজানা' }} · PON {{ $g['pon'] ?? 'অজানা' }}</div>
      <div class="ai-muted">{{ $g['affected_onus'] ?? 0 }} affected ONU(s) · {{ $g['affected_customers'] ?? 0 }} affected customer(s) · confidence {{ $g['confidence'] ?? 'low' }}</div>
      <div class="mt-1">{{ implode(', ', $g['statuses'] ?? []) }}</div>
      <div class="ai-muted mt-1">{{ ($g['evidence'][0] ?? 'Shared-path correlation only; root cause is not confirmed.') }}</div>
    </div>
   @empty <div class="ai-muted small">বর্তমানে একই OLT/PON পথে একাধিক সমস্যাগ্রস্ত ONU শনাক্ত হয়নি।</div>@endforelse
   <div class="ai-readonly mt-2">Correlation is evidence only; এআই ইঞ্জিনিয়ার does not change service state.</div>
  </div></div>
  <div class="col-xl-5"><div class="ai-card p-4 h-100">
   <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="bi bi-person-check me-1"></i> ONU ↔ গ্রাহক সম্ভাব্য মিল</h2><span class="badge text-bg-warning">{{ count($matchingCandidates['matches'] ?? []) }}</span></div>
   @if(session('ai_mapping_message'))<div class="alert alert-success py-2">{{ session('ai_mapping_message') }}</div>@endif
   @if($errors->has('ai_mapping'))<div class="alert alert-warning py-2">{{ $errors->first('ai_mapping') }}</div>@endif
   @forelse(($matchingCandidates['matches'] ?? []) as $m)
    <div class="border rounded p-2 mb-2 small">
      <div class="fw-semibold">{{ $m['candidate']['customer_id'] ?? '—' }} · {{ $m['candidate']['customer_name'] ?? '—' }}</div>
      <div class="ai-muted">ONU {{ $m['onu_id'] ?? '—' }} · PON {{ $m['pon'] ?? '—' }} · score {{ $m['candidate']['score'] ?? 0 }}/100</div>
      <div class="mt-1">{{ implode(' · ', $m['candidate']['reasons'] ?? []) }}</div>
      @if((int)($m['candidate']['score'] ?? 0) >= 60 && !empty($m['candidate']['customer_db_id']))
       <form method="POST" action="{{ route('ai-engineer.map-onu') }}" class="mt-2" onsubmit="return confirm('Exact MAC মিলটি যাচাই করে এই ONU-কে গ্রাহকের সঙ্গে ম্যাপ করবেন?');">
        @csrf
        <input type="hidden" name="mapping_id" value="{{ (int)($m['mapping_id'] ?? 0) }}">
        <input type="hidden" name="customer_id" value="{{ (int)($m['candidate']['customer_db_id'] ?? 0) }}">
        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-link-45deg me-1"></i> exact match ম্যাপ করুন</button>
       </form>
      @else
       <div class="ai-readonly mt-2">সরাসরি ম্যাপ করার আগে exact MAC মিল প্রয়োজন।</div>
      @endif
    </div>
   @empty <div class="ai-muted small">কোনো সম্ভাব্য মিল পাওয়া যায়নি।</div>@endforelse
   <div class="ai-readonly mt-2">Score 60+ exact MAC match হলে ম্যাপিং বাটন চালু হবে; কম স্কোর, অস্পষ্ট মিল বা conflict হলে manual review প্রয়োজন।</div>
  </div></div>
 </div>

 <div class="ai-card p-3 mb-4"><div class="d-flex flex-wrap justify-content-between gap-2"><span><i class="bi bi-database-check me-1"></i> স্থানীয় ডায়াগনস্টিক তথ্য এখন পাওয়া যাচ্ছে।</span><span class="ai-readonly">Conversational AI: {{ ($overview['ai_configured'] ?? $overview['openai_configured']) ? strtoupper($overview['ai_provider'] ?? 'external') . ' কনফিগার করা' : 'স্থানীয় ডায়াগনস্টিক মোড' }} · ডায়াগনস্টিক read-only · exact MAC mapping only</span></div></div>
<div class="ai-card p-4 mb-4"><div class="row g-3 align-items-end"><div class="col-lg-9"><label class="form-label fw-semibold">গ্রাহক</label><input id="aiগ্রাহক" class="form-control form-control-lg" value="{{ $customerId }}" placeholder="গ্রাহক ID, name, mobile or PPPoE username" autocomplete="off"><div id="aiSuggestions" class="mt-2 d-flex flex-wrap gap-2"></div></div><div class="col-lg-3"><button id="aiডায়াগনসিস" class="btn btn-primary btn-lg w-100"><i class="bi bi-stars me-1"></i> ডায়াগনসিস</button></div></div><div class="mt-3 d-flex flex-wrap gap-2"><button class="ai-chip js-question" data-q="এই গ্রাহক অফলাইনে কেন?">এই গ্রাহক অফলাইনে কেন?</button><button class="ai-chip js-question" data-q="What is wrong with this customer's service path?">সেবা পথ পরীক্ষা করুন</button><button class="ai-chip js-question" data-q="What billing issue should I check?">বিলিং পরীক্ষা করুন</button></div></div>
 <div class="ai-card p-4 mb-4"><div id="aiChatLog" class="ai-chat mb-3"><div class="ai-bubble"><strong>এআই ইঞ্জিনিয়ার:</strong> এই গ্রাহক, ডিভাইস, বিলিং বা নেটওয়ার্ক বিভ্রাট সম্পর্কে প্রশ্ন করুন।</div></div><div class="input-group"><input id="aiQuestion" class="form-control" placeholder="এই গ্রাহক অফলাইনে কেন?"><button id="aiAsk" class="btn btn-primary">এআই ইঞ্জিনিয়ারকে জিজ্ঞেস করুন</button></div></div>
 <div id="aiResult" class="ai-card p-4 ai-chat">@if($diagnosis && ($diagnosis['ok'] ?? false)) @include('xlink.partials.ai-engineer-result',['diagnosis'=>$diagnosis]) @else <div class="text-center py-5 ai-muted"><i class="bi bi-chat-square-text fs-1"></i><h2 class="h5 mt-3">এআই ইঞ্জিনিয়ারকে জিজ্ঞেস করুন</h2><p class="mb-0">Open a customer and ask a question. No network configuration or customer status will be changed.</p></div>@endif</div>
 <div class="text-center mt-3 ai-readonly"><i class="bi bi-shield-check me-1"></i> ডায়াগনস্টিক read-only। শুধু unique exact ONU MAC ↔ PPPoE caller MAC মিল হলে mapping record সেভ হয়; OLT provisioning, reboot, customer status change ও delete permission দেওয়া হয়নি।</div>
</div>
<script>
(() => { const input=document.getElementById('aiগ্রাহক'), btn=document.getElementById('aiডায়াগনসিস'), result=document.getElementById('aiResult'), suggestions=document.getElementById('aiSuggestions');
 async function askAI(){ const q=document.getElementById('aiQuestion').value.trim(); if(!q)return; const log=document.getElementById('aiChatLog'); const customer=input.value.trim(); log.innerHTML += '<div class="text-end mb-2"><span class="badge text-bg-primary p-2">'+escapeHtml(q)+'</span></div>'; document.getElementById('aiQuestion').value=''; const box=document.createElement('div'); box.className='ai-bubble mb-2'; box.innerHTML='<strong>এআই ইঞ্জিনিয়ার:</strong> <span class="spinner-border spinner-border-sm"></span> লাইভ ডায়াগনস্টিক তথ্য পরীক্ষা করা হচ্ছে...'; log.appendChild(box); try{const r=await fetch('{{ route('ai-engineer.chat') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({question:q,customer_id:customer})}); const d=await r.json(); box.innerHTML='<strong>এআই ইঞ্জিনিয়ার:</strong> '+escapeHtml(d.message||'কোনো উত্তর পাওয়া যায়নি।'); if(!d.configured) box.innerHTML += '<div class="small text-warning mt-2">কথোপকথনমূলক এআই কনফিগার করা নেই; স্থানীয় ডায়াগনস্টিক চালু আছে।</div>';}catch(e){box.innerHTML='<strong>এআই ইঞ্জিনিয়ার:</strong> '+escapeHtml(e.message);}}
 document.getElementById('aiAsk').addEventListener('click',askAI); document.getElementById('aiQuestion').addEventListener('keydown',e=>{if(e.key==='Enter')askAI();});
 async function diagnose(){ const id=input.value.trim(); if(!id){input.focus();return;} btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> পরীক্ষা করা হচ্ছে...'; try{const r=await fetch('{{ route('ai-engineer.diagnose') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({customer_id:id})}); const d=await r.json(); if(!d.ok) throw new Error(d.message||'গ্রাহক not found'); result.innerHTML=render(d);}catch(e){result.innerHTML='<div class="alert alert-danger mb-0">'+escapeHtml(e.message)+'</div>';}finally{btn.disabled=false;btn.innerHTML='<i class="bi bi-stars me-1"></i> ডায়াগনসিস';}}
 function render(d){const sev=escapeHtml(d.severity);const p=d.service_path||{};const pathItems=[['গ্রাহক',p.customer],['PPPoE',p.pppoe],['Router',p.router],['ONU/OLT',p.onu],['Billing',p.billing]];const pathHtml=pathItems.map(([label,x])=>{x=x||{};const state=x.state||'অজানা';const cls=['active','clear'].includes(String(state).toLowerCase())?'success':['disabled','inactive','los','offline','down','due','missing'].includes(String(state).toLowerCase())?'danger':'secondary';const detail=label==='PPPoE'?(x.username||'সংযুক্ত নয়')+(x.live_session?' · লাইভ '+x.live_session.state:''):label==='Router'?(x.name?x.name+' · '+(x.ip||'')+(x.latency_ms!==null&&x.latency_ms!==undefined?' · '+x.latency_ms+' ms':'')+' · চেক '+(x.check_freshness||'অজানা'):'বরাদ্দ নেই'):label==='ONU/OLT'?(x.pon?'PON '+x.pon+' · ONU '+(x.onu_id||'')+' · '+(x.olt_name||'OLT')+(x.rx_power!==null&&x.rx_power!==undefined?' · RX '+x.rx_power+' dBm':'')+' · '+(x.olt_state||'অজানা'):'ম্যাপ করা নেই'):label==='Billing'?(x.due>0?'Due '+x.due:'Clear'):state;return '<div class="col-md"><div class="border rounded-3 p-3 h-100"><div class="small text-muted">'+escapeHtml(label)+'</div><div class="fw-semibold mt-1">'+escapeHtml(detail)+'</div><span class="badge text-bg-'+cls+' mt-2">'+escapeHtml(state)+'</span></div></div>';}).join('');return `<div class="ai-result ${sev} ps-3"><div class="d-flex justify-content-between align-items-start"><div><div class="small text-uppercase text-muted">${escapeHtml(d.customer.id)} · ${escapeHtml(d.customer.status)}</div><h2 class="h5 mt-1 mb-2">${escapeHtml(d.summary)}</h2></div><span class="badge text-bg-${sev==='critical'?'danger':sev==='warning'?'warning':'primary'}">${sev}</span></div><h3 class="h6 mt-4">সেবা পথ</h3><div class="row g-2 mb-4">${pathHtml}</div><div class="small text-muted mt-1 mb-3">Confidence: <strong>${escapeHtml(String(d.diagnostic_confidence||'low').toUpperCase())}</strong> · ${escapeHtml(d.confidence_reason||'Live/current evidence is insufficient to confirm a root cause.')}</div><h3 class="h6 mt-4">সম্ভাব্য কারণ</h3><ul>${d.likely_causes.map(x=>`<li>${escapeHtml(x)}</li>`).join('')}</ul><h3 class="h6 mt-3">প্রমাণ</h3><ul>${(d.evidence.length?d.evidence:['অতিরিক্ত কোনো প্রমাণ রেকর্ড নেই।']).map(x=>`<li>${escapeHtml(x)}</li>`).join('')}</ul><h3 class="h6 mt-3">প্রস্তাবিত যাচাই</h3><ul>${d.recommended_checks.map(x=>`<li>${escapeHtml(x)}</li>`).join('')}</ul><div class="ai-actions d-flex gap-2 mt-4"><button class="btn btn-outline-success btn-sm" onclick="aiFeedback('up')">👍 সহায়ক</button><button class="btn btn-outline-secondary btn-sm" onclick="aiFeedback('down')">👎 সহায়ক নয়</button></div><div class="small text-muted mt-3">Read-only diagnostic · ${escapeHtml(d.generated_at)}</div></div>`;}
 function escapeHtml(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
 window.aiFeedback=async rating=>{await fetch('{{ route('ai-engineer.feedback') }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({rating,customer_id:input.value.trim()})});}; btn.addEventListener('click',diagnose); input.addEventListener('keydown',e=>{if(e.key==='Enter')diagnose();}); document.querySelectorAll('.js-question').forEach(b=>b.addEventListener('click',()=>{document.getElementById('aiQuestion').value=b.dataset.q;document.getElementById('aiQuestion').focus();}));
 let timer; input.addEventListener('input',()=>{clearTimeout(timer); const q=input.value.trim(); if(q.length<2){suggestions.innerHTML='';return;} timer=setTimeout(async()=>{const r=await fetch('{{ route('ai-engineer.search') }}?q='+encodeURIComponent(q),{headers:{Accept:'application/json'}}); const items=await r.json(); suggestions.innerHTML=items.map(x=>`<button type="button" class="ai-chip" data-id="${escapeHtml(x.customer_unique_id)}">${escapeHtml(x.customer_unique_id)} · ${escapeHtml(x.customer_name||'')}</button>`).join(''); suggestions.querySelectorAll('button').forEach(b=>b.onclick=()=>{input.value=b.dataset.id;suggestions.innerHTML='';diagnose();});},250);});
})();
</script>
</x-app-layout>
