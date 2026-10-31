<div class="ai-result {{ $diagnosis['severity'] }} ps-3">
 <div class="d-flex justify-content-between align-items-start"><div><div class="small text-uppercase text-muted">{{ $diagnosis['customer']['id'] }} · {{ $diagnosis['customer']['status'] }}</div><h2 class="h5 mt-1 mb-2">{{ $diagnosis['summary'] }}</h2></div><span class="badge text-bg-{{ $diagnosis['severity']==='critical'?'danger':($diagnosis['severity']==='warning'?'warning':'primary') }}">{{ $diagnosis['severity'] }}</span></div>
 <div class="small text-muted mt-1 mb-3">Diagnostic confidence: <strong>{{ strtoupper($diagnosis['diagnostic_confidence'] ?? 'low') }}</strong> · {{ $diagnosis['confidence_reason'] ?? 'Insufficient current evidence to confirm a root cause.' }}</div>
 <h3 class="h6 mt-4">Likely causes</h3><ul>@foreach($diagnosis['likely_causes'] as $item)<li>{{ $item }}</li>@endforeach</ul>
 <h3 class="h6 mt-3">Evidence</h3><ul>@forelse($diagnosis['evidence'] as $item)<li>{{ $item }}</li>@empty<li>No additional evidence recorded.</li>@endforelse</ul>
 <h3 class="h6 mt-3">Recommended checks</h3><ul>@foreach($diagnosis['recommended_checks'] as $item)<li>{{ $item }}</li>@endforeach</ul>
 <div class="ai-actions d-flex gap-2 mt-4"><button class="btn btn-outline-success btn-sm" onclick="aiFeedback('up')">👍 Helpful</button><button class="btn btn-outline-secondary btn-sm" onclick="aiFeedback('down')">👎 Not helpful</button></div>
 <div class="small text-muted mt-3">Read-only diagnostic · {{ $diagnosis['generated_at'] }}</div>
</div>
