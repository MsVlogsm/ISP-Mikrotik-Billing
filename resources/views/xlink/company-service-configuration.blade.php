<x-app-layout>
@php
    $cardLabels = ['connection_type'=>'Connection Types','protocol'=>'Network Protocols','customer_type'=>'Customer Types','billing_status'=>'Billing Statuses','bandwidth_type'=>'Bandwidth Types'];
    $singularLabels = ['connection_type'=>'Connection Type','protocol'=>'Protocol','customer_type'=>'Customer Type','billing_status'=>'Status','bandwidth_type'=>'Bandwidth Type'];
    $tabIcons = ['connection_type'=>'bi-broadcast-pin','protocol'=>'bi-diagram-3','customer_type'=>'bi-people','billing_status'=>'bi-activity','bandwidth_type'=>'bi-lightning-charge'];
    $categoryCount = (int) ($counts[$category]->total ?? 0);
@endphp
<div class="container-fluid px-2 px-lg-3 sc-page">
    <div class="sc-heading">
        <nav class="sc-breadcrumb" aria-label="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a><i class="bi bi-chevron-right"></i>
            <a href="{{ route('company-settings') }}">Company Settings</a><i class="bi bi-chevron-right"></i>
            <strong>Service Configuration</strong>
        </nav>
        <button type="button" class="btn sc-primary-btn" data-bs-toggle="modal" data-bs-target="#scAddModal">
            <i class="bi bi-plus-lg me-1"></i> Add {{ $singularLabels[$category] }}
        </button>
    </div>

    @if(session('company_settings_message'))<div class="alert alert-success sc-alert">{{ session('company_settings_message') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger sc-alert">{{ $errors->first() }}</div>@endif

    <section class="sc-summary-grid" aria-label="Service configuration summary">
        @foreach($cardLabels as $key=>$label)
            <a class="sc-summary-card text-decoration-none {{ $category===$key?'sc-summary-selected':'' }}" href="{{ route('company-settings.service-configuration',['category'=>$key]) }}">
                <span class="sc-summary-icon sc-icon-{{ $key }}"><i class="bi {{ $tabIcons[$key] }}"></i></span>
                <span class="sc-summary-copy">
                    <span class="sc-summary-label">{{ $label }}</span>
                    <strong>{{ (int)($counts[$key]->total??0) }}</strong>
                    <small>{{ $key==='billing_status'?(int)($counts[$key]->system_total??0).' system':(int)($counts[$key]->active_total??0).' active' }}</small>
                </span>
            </a>
        @endforeach
    </section>

    <nav class="sc-tabs" aria-label="Service configuration categories">
        @foreach($categories as $key=>$label)
            <a href="{{ route('company-settings.service-configuration',['category'=>$key]) }}" class="sc-tab {{ $category===$key?'active':'' }}">
                <i class="bi {{ $tabIcons[$key] }}"></i><span>{{ $label }}</span><b>{{ (int)($counts[$key]->total??0) }}</b>
            </a>
        @endforeach
    </nav>

    <section class="sc-data-card">
        <div class="sc-card-heading">
            <div class="sc-card-title"><i class="bi {{ $tabIcons[$category] }}"></i><h2>{{ $categories[$category] }}</h2></div>
            <span class="sc-count-pill">{{ $categoryCount }} {{ $category==='billing_status'?'statuses':($category==='connection_type'||$category==='bandwidth_type'?'types':strtolower($categories[$category])) }}</span>
        </div>

        <form method="GET" action="{{ route('company-settings.service-configuration') }}" class="sc-toolbar" id="scFilterForm">
            <input type="hidden" name="category" value="{{ $category }}">
            <label class="sc-search"><i class="bi bi-search"></i>
                <input type="search" name="q" value="{{ $search }}" placeholder="Search {{ strtolower($categories[$category]) }} by name, key..." autocomplete="off" aria-label="Search service options">
                @if($search!=='')<a href="{{ route('company-settings.service-configuration',['category'=>$category,'status'=>$status]) }}" class="sc-clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>@endif
            </label>
            <select class="sc-status-filter" name="status" aria-label="Filter by status" onchange="document.getElementById('scFilterForm').submit()">
                <option value="all" {{ $status==='all'?'selected':'' }}>All Status</option>
                <option value="active" {{ $status==='active'?'selected':'' }}>Active</option>
                <option value="inactive" {{ $status==='inactive'?'selected':'' }}>Inactive</option>
            </select>
            <button class="visually-hidden" type="submit">Search</button>
        </form>

        <div class="table-responsive sc-table-wrap">
            <table class="table sc-table align-middle mb-0">
                <thead><tr>
                    <th class="sc-col-sl">SL</th>
                    @if($category==='connection_type')
                        <th class="text-center">CONNECTION TYPE NAME</th><th class="text-center">DESCRIPTION</th>
                    @elseif($category==='protocol')
                        <th class="text-center">PROTOCOL NAME</th><th class="text-center">MACHINE KEY</th><th class="text-center">DESCRIPTION</th>
                    @elseif($category==='customer_type')
                        <th class="text-center">CUSTOMER TYPE NAME</th><th class="text-center">CODE</th><th class="text-center">DESCRIPTION</th>
                    @elseif($category==='billing_status')
                        <th class="text-center">NAME</th><th class="text-center">MACHINE KEY</th><th class="text-center">DESCRIPTION</th>
                    @else
                        <th class="text-center">BANDWIDTH TYPE NAME</th><th class="text-center">SLUG / KEY</th><th class="text-center">DESCRIPTION</th><th class="text-center">SORT ORDER</th>
                    @endif
                    <th class="text-center sc-col-status">STATUS</th><th class="text-center sc-col-actions">ACTIONS</th>
                </tr></thead>
                <tbody>
                @forelse($options as $option)
                    @php
                        $statusTone=match(strtolower($option->code)){
                            'active'=>'success','expired'=>'danger','suspended'=>'warning','left'=>'secondary','pending'=>'info',default=>'primary'
                        };
                    @endphp
                    <tr>
                        <td class="sc-row-number">{{ $options->firstItem()+$loop->index }}</td>
                        @if($category==='connection_type')
                            <td class="text-center fw-semibold">{{ $option->name }}</td><td class="text-center sc-description">{{ $option->description?:'—' }}</td>
                        @elseif($category==='protocol')
                            <td class="text-center fw-semibold">{{ $option->name }}</td><td class="text-center"><code class="sc-key">{{ $option->code }}</code></td><td class="text-center sc-description">{{ $option->description?:'—' }}</td>
                        @elseif($category==='customer_type')
                            <td class="text-center fw-semibold">{{ $option->name }}</td><td class="text-center"><code class="sc-key">{{ $option->code }}</code></td><td class="text-center sc-description">{{ $option->description?:'—' }}</td>
                        @elseif($category==='billing_status')
                            <td class="text-center"><span class="sc-status-name sc-status-name-{{ $statusTone }}">{{ $option->name }}</span>@if($option->is_system)<span class="sc-system-tag">SYSTEM</span>@endif</td>
                            <td class="text-center"><code class="sc-key">{{ $option->code }}</code></td><td class="text-center sc-description">{{ $option->description?:'—' }}</td>
                        @else
                            <td class="text-center fw-semibold">{{ $option->name }}</td><td class="text-center"><code class="sc-key">{{ $option->code }}</code></td><td class="text-center sc-description">{{ $option->description?:'—' }}</td><td class="text-center">{{ (int)($option->sort_order??0) }}</td>
                        @endif
                        <td class="text-center">
                            <form method="POST" action="{{ route('company-settings.service-configuration.toggle',$option) }}" class="m-0 d-inline">@csrf
                                <label class="sc-switch" title="{{ $option->is_active?'Disable':'Enable' }} {{ $option->name }}">
                                    <input type="checkbox" {{ $option->is_active?'checked':'' }} onchange="this.form.submit()" aria-label="Toggle {{ $option->name }}"><span></span>
                                </label>
                            </form>
                        </td>
                        <td class="text-center"><div class="sc-actions">
                            <button type="button" class="sc-icon-btn sc-edit-btn" title="Edit {{ $option->name }}" data-bs-toggle="modal" data-bs-target="#scEditModal"
                                data-update-url="{{ route('company-settings.service-configuration.update',$option) }}" data-name="{{ $option->name }}"
                                data-description="{{ $option->description }}" data-sort-order="{{ (int)($option->sort_order??0) }}"><i class="bi bi-pencil"></i></button>
                            @if($category === 'billing_status' && $option->is_system)
                                <button type="button" class="sc-icon-btn sc-delete-disabled" title="System billing statuses are protected" disabled aria-label="System billing status protected"><i class="bi bi-trash3"></i></button>
                            @else
                                <form method="POST" action="{{ route('company-settings.service-configuration.delete',$option) }}" class="m-0" onsubmit="return confirm('Delete {{ addslashes($option->name) }}? If existing records use this option, deletion will be blocked.');">@csrf @method('DELETE')
                                    <button type="submit" class="sc-icon-btn sc-delete-btn" title="Delete {{ $option->name }}"><i class="bi bi-trash3"></i></button>
                                </form>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $category==='connection_type'?5:($category==='bandwidth_type'?7:6) }}" class="sc-empty">
                        <i class="bi bi-inbox"></i><strong>No {{ strtolower($categories[$category]) }} found</strong>
                        <span>Try changing the search or status filter, or add a new option.</span>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="sc-table-footer"><span>Showing {{ $options->firstItem()??0 }}–{{ $options->lastItem()??0 }} of {{ $options->total() }}</span><div>{{ $options->onEachSide(1)->links() }}</div></div>
    </section>
</div>

<div class="modal fade sc-modal" id="scAddModal" tabindex="-1" aria-labelledby="scAddModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="{{ route('company-settings.service-configuration.store') }}">@csrf
            <input type="hidden" name="category" value="{{ $category }}">
            <div class="modal-header"><div><span class="sc-modal-kicker">SERVICE CONFIGURATION</span><h5 class="modal-title" id="scAddModalLabel">Add {{ $singularLabels[$category] }}</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label class="form-label">Display Name</label><input name="name" class="form-control sc-input mb-3" required maxlength="120" placeholder="Enter display name">
                <label class="form-label">Machine Key / Code</label><input name="code" class="form-control sc-input mb-1" required maxlength="80" pattern="[A-Za-z0-9_-]+" placeholder="e.g. fiber_plus">
                <div class="sc-help mb-3">Use a stable key. Existing customer records may store this value.</div>
                <label class="form-label">Description</label><textarea name="description" class="form-control sc-input mb-3" rows="3" maxlength="500" placeholder="Describe this option"></textarea>
                @if($category==='bandwidth_type')<label class="form-label">Sort Order</label><input type="number" min="0" max="9999" name="sort_order" class="form-control sc-input" value="0">@endif
            </div>
            <div class="modal-footer"><button type="button" class="btn sc-secondary-btn" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn sc-primary-btn"><i class="bi bi-plus-lg me-1"></i> Add Option</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade sc-modal" id="scEditModal" tabindex="-1" aria-labelledby="scEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" id="scEditForm">@csrf @method('PUT')
            <div class="modal-header"><div><span class="sc-modal-kicker">SERVICE CONFIGURATION</span><h5 class="modal-title" id="scEditModalLabel">Edit Service Option</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label class="form-label">Display Name</label><input name="name" id="scEditName" class="form-control sc-input mb-3" required maxlength="120">
                <label class="form-label">Description</label><textarea name="description" id="scEditDescription" class="form-control sc-input mb-3" rows="3" maxlength="500"></textarea>
                @if($category==='bandwidth_type')<label class="form-label">Sort Order</label><input type="number" min="0" max="9999" name="sort_order" id="scEditSortOrder" class="form-control sc-input">@endif
            </div>
            <div class="modal-footer"><button type="button" class="btn sc-secondary-btn" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn sc-primary-btn"><i class="bi bi-check2 me-1"></i> Save Changes</button></div>
        </form>
    </div></div>
</div>

<style>
.sc-page{--sc-bg:#111821;--sc-line:#26313e;--sc-text:#e7edf5;--sc-muted:#98a9bd;color:var(--sc-text);padding-bottom:1.4rem}
.sc-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem;min-height:56px;margin:0 0 14px;padding:0 2px}
.sc-breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:12px;font-size:14px}.sc-breadcrumb a{color:#8eacd0;text-decoration:none}.sc-breadcrumb a:hover{color:#fff}.sc-breadcrumb i{font-size:10px;color:#68798c}.sc-breadcrumb strong{color:#f2f6fb;font-weight:650}
.sc-primary-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #5badff;background:#4da3f7;color:#071321;border-radius:10px;padding:10px 16px;font-weight:650;font-size:13px;white-space:nowrap}.sc-primary-btn:hover{background:#72b7ff;color:#071321}
.sc-secondary-btn{background:#202b39;border:1px solid #354357;color:#dce6f1;border-radius:9px;padding:9px 16px}.sc-alert{border-radius:10px;margin-bottom:14px}
.sc-summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:14px}.sc-summary-card{display:flex;align-items:center;gap:14px;min-width:0;min-height:94px;padding:14px 16px;background:linear-gradient(145deg,#151d28,#111821);border:1px solid #283444;border-radius:15px;color:var(--sc-text);transition:border-color .18s,transform .18s}.sc-summary-card:hover,.sc-summary-selected{border-color:#3b78b4;transform:translateY(-1px);color:var(--sc-text)}
.sc-summary-icon{width:48px;height:48px;flex:0 0 48px;border-radius:13px;display:grid;place-items:center;font-size:22px;background:#18304a;color:#53b3ff}.sc-icon-protocol{background:#10303c;color:#20c8ea}.sc-icon-customer_type{background:#332b18;color:#ffbd27}.sc-icon-billing_status{background:#10342f;color:#10d5a6}.sc-icon-bandwidth_type{background:#2d2042;color:#c17bff}
.sc-summary-copy{display:flex;flex-direction:column;min-width:0;line-height:1.25}.sc-summary-label{font-size:12px;color:#a8bad0;margin-bottom:3px}.sc-summary-copy strong{font-size:21px;line-height:1.1;color:#fff;font-weight:750}.sc-summary-copy small{font-size:11px;color:#b8c5d5;margin-top:3px}
.sc-tabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;background:#141b25;border:1px solid #283444;border-radius:14px;padding:8px;margin-bottom:14px;min-height:62px}.sc-tab{display:inline-flex;align-items:center;gap:9px;border-radius:10px;padding:10px 14px;color:#d5e0ed;text-decoration:none;font-size:13px;font-weight:650;white-space:nowrap}.sc-tab i{font-size:16px}.sc-tab b{font-size:11px;line-height:19px;min-width:20px;text-align:center;border-radius:7px;padding:0 5px;background:#222c3a;color:#d8e3f0}.sc-tab:hover{background:#202d3d;color:#fff}.sc-tab.active{background:#4da3f7;color:#061625}.sc-tab.active b{background:#79baff;color:#0a1c2e}
.sc-data-card{background:#141b25;border:1px solid #2a3543;border-radius:14px;overflow:hidden}.sc-card-heading{height:52px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 15px;border-bottom:1px solid #2a3543}.sc-card-title{display:flex;align-items:center;gap:9px}.sc-card-title i{color:#93a9c0;font-size:16px}.sc-card-title h2{font-size:15px;font-weight:700;margin:0;color:#f0f5fb}.sc-count-pill{background:#222d3b;border-radius:8px;padding:5px 10px;color:#e1eaf4;font-size:11px;font-weight:650}
.sc-toolbar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #2a3543}.sc-search{height:40px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px;border:1px solid #364355;border-radius:9px;padding:0 12px;background:#0e141c;color:#93a8bf}.sc-search input{width:100%;height:100%;border:0;outline:0;background:transparent;color:#eaf1f8;font-size:13px}.sc-search input::placeholder{color:#7f91a7}.sc-clear{color:#91a4ba}.sc-status-filter{height:40px;min-width:138px;padding:0 34px 0 12px;border:1px solid #364355;border-radius:9px;background:#1a2431;color:#e5edf6;font-size:13px}
.sc-table-wrap{width:100%}.sc-table{--bs-table-bg:transparent;--bs-table-color:#dce5f0;--bs-table-border-color:#26313e;--bs-table-hover-bg:#192534;--bs-table-hover-color:#fff}.sc-table thead th{height:42px;background:#1a222e;color:#cbd7e5;font-size:11px;font-weight:750;letter-spacing:.025em;white-space:nowrap;border-bottom:1px solid #344153;padding:10px 12px}.sc-table tbody td{height:46px;padding:8px 12px;border-bottom:1px solid #25303c;font-size:13px;color:#d9e3ef}.sc-table tbody tr:last-child td{border-bottom:0}.sc-table tbody tr:hover{--bs-table-bg:transparent;background:#192431}.sc-col-sl{width:55px}.sc-row-number{color:#98abc0!important;text-align:center;width:55px}.sc-description{color:#9fb1c7!important;min-width:220px}.sc-key{display:inline-block;background:#202a37;color:#9fbce0;border-radius:5px;padding:3px 7px;font-size:11px}.sc-col-status{width:110px}.sc-col-actions{width:115px}
.sc-switch{display:inline-block;position:relative;width:39px;height:21px;vertical-align:middle;cursor:pointer}.sc-switch input{opacity:0;width:0;height:0}.sc-switch span{position:absolute;inset:0;background:#465363;border-radius:20px;transition:.18s}.sc-switch span:before{content:"";position:absolute;left:3px;top:3px;width:15px;height:15px;background:#eaf0f7;border-radius:50%;transition:.18s}.sc-switch input:checked+span{background:#4da3f7}.sc-switch input:checked+span:before{transform:translateX(18px);background:#fff}.sc-switch input:focus-visible+span{outline:2px solid #9bcfff;outline-offset:2px}
.sc-actions{display:flex;align-items:center;justify-content:center;gap:9px}.sc-icon-btn{width:27px;height:28px;display:inline-grid;place-items:center;background:transparent;border:0;border-radius:6px;font-size:14px}.sc-edit-btn{color:#e0e9f4}.sc-edit-btn:hover{background:#26364a;color:#66b4ff}.sc-delete-btn{color:#e44c5b}.sc-delete-btn:hover{background:#3b232a;color:#ff7784}.sc-delete-disabled{color:#4b5663;cursor:not-allowed;opacity:.55}
.sc-status-name{display:inline-block;border-radius:20px;padding:4px 9px;font-size:11px;font-weight:750;border:1px solid transparent}.sc-status-name-success{color:#00d49b;background:#063b30;border-color:#08795f}.sc-status-name-danger{color:#ff5070;background:#40182a;border-color:#8e2342}.sc-status-name-warning{color:#ffc14d;background:#493214;border-color:#916016}.sc-status-name-secondary{color:#b7c0ca;background:#2b3039;border-color:#444b57}.sc-status-name-info{color:#31d4f0;background:#063b4a;border-color:#08718b}.sc-status-name-primary{color:#68b6ff;background:#102d48;border-color:#235f93}.sc-system-tag{display:inline-block;margin-left:5px;border:1px solid #225b91;color:#5fb2ff;border-radius:6px;padding:3px 6px;font-size:10px;font-weight:750}
.sc-empty{text-align:center!important;padding:38px 14px!important;color:#8ea1b6!important}.sc-empty i{display:block;font-size:25px;margin-bottom:8px}.sc-empty strong,.sc-empty span{display:block}.sc-empty strong{color:#dae5f0;margin-bottom:3px}.sc-empty span{font-size:12px}
.sc-table-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:52px;padding:8px 14px;color:#9badc1;font-size:12px;border-top:1px solid #283444}.sc-table-footer nav{display:flex}.sc-table-footer .pagination{margin:0;gap:5px}.sc-table-footer .page-link{border:1px solid #344153!important;background:#1a2431!important;color:#cbd9e8!important;border-radius:8px!important;font-size:12px;padding:6px 10px}.sc-table-footer .page-item.active .page-link{background:#4da3f7!important;border-color:#4da3f7!important;color:#071727!important}.sc-table-footer .page-item.disabled .page-link{color:#63758a!important;background:#151d27!important}
.sc-modal .modal-content{background:#151e29;border:1px solid #354355;border-radius:14px;color:#e7eef6;box-shadow:0 18px 60px #0008}.sc-modal .modal-header,.sc-modal .modal-footer{border-color:#2c3948;padding:16px 19px}.sc-modal .modal-body{padding:18px 19px}.sc-modal .form-label{font-size:12px;font-weight:650;color:#c8d6e5;margin-bottom:6px}.sc-modal-kicker{display:block;font-size:9px;letter-spacing:.1em;color:#68b5ff;font-weight:750;margin-bottom:4px}.sc-modal .modal-title{font-size:17px;font-weight:700}.sc-input{background:#0e151e;border:1px solid #354457;color:#eef4fb;border-radius:8px;font-size:13px}.sc-input:focus{background:#0e151e;border-color:#4da3f7;color:#fff;box-shadow:0 0 0 .18rem #4da3f722}.sc-help{font-size:11px;color:#92a7bd}
@media(max-width:1100px){.sc-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:700px){.sc-heading{align-items:flex-start;flex-direction:column;padding:8px 0}.sc-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.sc-summary-card{min-height:78px;padding:10px;gap:9px}.sc-summary-icon{width:39px;height:39px;flex-basis:39px;font-size:18px}.sc-summary-label{font-size:10px}.sc-summary-copy strong{font-size:18px}.sc-tabs{overflow-x:auto;flex-wrap:nowrap}.sc-tab{padding:9px 11px;font-size:12px}.sc-toolbar{align-items:stretch;flex-direction:column}.sc-status-filter{width:100%}.sc-table{min-width:760px}.sc-table-footer{align-items:flex-start;flex-direction:column}.sc-breadcrumb{gap:7px;font-size:12px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const editModal=document.getElementById('scEditModal');
    if(editModal){editModal.addEventListener('show.bs.modal',function(event){
        const trigger=event.relatedTarget;if(!trigger)return;
        const form=document.getElementById('scEditForm');form.action=trigger.dataset.updateUrl||'';
        document.getElementById('scEditName').value=trigger.dataset.name||'';
        document.getElementById('scEditDescription').value=trigger.dataset.description||'';
        const sortInput=document.getElementById('scEditSortOrder');if(sortInput)sortInput.value=trigger.dataset.sortOrder||'0';
    });}
    const searchInput=document.querySelector('#scFilterForm input[name="q"]');
    if(searchInput){let timer;searchInput.addEventListener('input',function(){window.clearTimeout(timer);timer=window.setTimeout(function(){document.getElementById('scFilterForm').submit();},450);});}
});
</script>
</x-app-layout>
