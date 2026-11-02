<x-app-layout>
@php
    $singular = ['zones'=>'Zone','sub_zones'=>'Sub-Zone','distribution_boxes'=>'Distribution Box','districts'=>'District','upazilas'=>'Upazila'];
    $icons = ['zones'=>'bi-geo-alt','sub_zones'=>'bi-diagram-2','distribution_boxes'=>'bi-hdd-network','districts'=>'bi-map','upazilas'=>'bi-pin-map'];
    $isBox = $tab === 'distribution_boxes';
    $editBase = route('company-settings.area-zone-setup.update', ['tab'=>$tab,'id'=>'999999999']);
@endphp
<div class="container-fluid px-2 px-lg-3 az-page">
    <div class="az-heading">
        <nav class="az-breadcrumb" aria-label="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a><i class="bi bi-chevron-right"></i>
            <a href="{{ route('company-settings') }}">Company Settings</a><i class="bi bi-chevron-right"></i>
            <strong>Area/Zone Setup</strong>
        </nav>
        <button type="button" class="btn az-primary-btn" data-bs-toggle="modal" data-bs-target="#azAddModal">
            <i class="bi bi-plus-lg me-1"></i> Add {{ $singular[$tab] }}
        </button>
    </div>

    @if(session('company_settings_message'))<div class="alert alert-success az-alert">{{ session('company_settings_message') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger az-alert">{{ $errors->first() }}</div>@endif

    <section class="az-summary-grid" aria-label="Area and zone summary">
        <article class="az-summary-card">
            <span class="az-icon az-blue"><i class="bi bi-geo-alt"></i></span>
            <span class="az-summary-copy"><span>Total Service Zones</span><strong>{{ $stats['zone_total'] }}</strong><small>{{ $stats['zone_active'] }} active zones</small></span>
        </article>
        <article class="az-summary-card">
            <span class="az-icon az-green"><i class="bi bi-diagram-3"></i></span>
            <span class="az-summary-copy"><span>Active Sub-Zones</span><strong>{{ $stats['sub_zone_active'] }}</strong><small>{{ $stats['sub_zone_total'] }} total sub-zones</small></span>
        </article>
        <article class="az-summary-card">
            <span class="az-icon az-purple"><i class="bi bi-hdd-network"></i></span>
            <span class="az-summary-copy"><span>Splitter &amp; TJ Boxes</span><strong>{{ $stats['box_total'] }}</strong><small>{{ $stats['splitter_total'] }} splitters · {{ $stats['tj_box_total'] }} TJ boxes</small></span>
        </article>
        <article class="az-summary-card">
            <span class="az-icon az-orange"><i class="bi bi-speedometer2"></i></span>
            <span class="az-summary-copy"><span>Port Capacity Used</span><strong>{{ number_format($stats['capacity_percent'],1) }}%</strong><small>{{ $stats['ports_used'] }} / {{ $stats['capacity_total'] }} TJ ports used</small></span>
        </article>
    </section>

    <nav class="az-tabs" aria-label="Area and zone sections">
        @foreach($tabs as $key=>$label)
            <a href="{{ route('company-settings.area-zone-setup',['tab'=>$key]) }}" class="az-tab {{ $tab===$key?'active':'' }}">
                <i class="bi {{ $icons[$key] }}"></i><span>{{ $label }}</span><b>{{ (int)($counts[$key]??0) }}</b>
            </a>
        @endforeach
    </nav>

    <section class="az-data-card">
        <div class="az-card-heading">
            <div class="az-card-title"><i class="bi {{ $icons[$tab] }}"></i><h2>{{ $tabs[$tab] }}</h2></div>
            <span class="az-count-pill">{{ (int)($counts[$tab]??0) }} records</span>
        </div>

        <form method="GET" action="{{ route('company-settings.area-zone-setup') }}" class="az-toolbar" id="azFilterForm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <label class="az-search"><i class="bi bi-search"></i><input type="search" name="q" value="{{ $search }}" placeholder="Search {{ strtolower($tabs[$tab]) }}..." autocomplete="off" aria-label="Search records">
                @if($search!=='')<a href="{{ route('company-settings.area-zone-setup',['tab'=>$tab,'status'=>$status]) }}" class="az-clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>@endif
            </label>
            <select name="status" class="az-status-filter" aria-label="Filter status" onchange="document.getElementById('azFilterForm').submit()">
                <option value="all" {{ $status==='all'?'selected':'' }}>All Status</option>
                <option value="active" {{ $status==='active'?'selected':'' }}>Active</option>
                <option value="inactive" {{ $status==='inactive'?'selected':'' }}>Inactive</option>
            </select>
            <button class="visually-hidden" type="submit">Search</button>
        </form>

        <div class="table-responsive az-table-wrap">
            <table class="table az-table align-middle mb-0">
                <thead><tr>
                    <th class="az-sl">SL</th>
                    @if($tab==='zones')
                        <th>ZONE NAME</th><th>ZONE CODE</th><th class="text-center">SUB-ZONES</th><th class="text-center">TJ BOXES</th><th>LINEMAN / MANAGER</th>
                    @elseif($tab==='sub_zones')
                        <th>SUB-ZONE NAME</th><th>ZONE</th><th>CODE</th><th class="text-center">DISTRIBUTION BOXES</th>
                    @elseif($isBox)
                        <th>BOX NAME</th><th>BOX CODE</th><th>ZONE</th><th>SUB-ZONE</th><th class="text-center">PORT CAPACITY</th><th class="text-center">USED PORTS</th>
                    @elseif($tab==='districts')
                        <th>DISTRICT NAME</th><th>CODE</th><th class="text-center">UPAZILAS</th>
                    @else
                        <th>UPAZILA NAME</th><th>DISTRICT</th><th>CODE</th>
                    @endif
                    <th class="text-center az-status-col">STATUS</th><th class="text-center az-action-col">ACTIONS</th>
                </tr></thead>
                <tbody>
                @forelse($options as $option)
                    <tr>
                        <td class="az-row-number">{{ $options->firstItem()+$loop->index }}</td>
                        @if($tab==='zones')
                            <td class="fw-semibold">{{ $option->name }}</td><td><code class="az-code">{{ $option->code ?: '—' }}</code></td>
                            <td class="text-center">{{ $option->sub_zones_count }}</td><td class="text-center">{{ $option->boxes_count }}</td>
                            <td><div class="az-manager">{{ $option->manager_name ?: '—' }}</div><small class="az-muted">{{ $option->manager_phone ?: '' }}</small></td>
                        @elseif($tab==='sub_zones')
                            <td class="fw-semibold">{{ $option->name }}</td><td>{{ $option->zone_name }}</td><td><code class="az-code">{{ $option->code ?: '—' }}</code></td><td class="text-center">{{ $option->boxes_count }}</td>
                        @elseif($isBox)
                            <td class="fw-semibold">{{ $option->name }}</td><td><code class="az-code">{{ $option->code }}</code></td><td>{{ $option->zone_name }}</td><td>{{ $option->sub_zone_name ?: '—' }}</td>
                            <td class="text-center">{{ $option->port_capacity }}</td><td class="text-center">{{ $option->ports_used }}</td>
                        @elseif($tab==='districts')
                            <td class="fw-semibold">{{ $option->name }}</td><td><code class="az-code">{{ $option->code ?: '—' }}</code></td><td class="text-center">{{ $option->upazilas_count }}</td>
                        @else
                            <td class="fw-semibold">{{ $option->name }}</td><td>{{ $option->district_name ?: '—' }}</td><td><code class="az-code">{{ $option->code ?: '—' }}</code></td>
                        @endif
                        <td class="text-center">
                            <form method="POST" action="{{ route('company-settings.area-zone-setup.toggle',['tab'=>$tab,'id'=>$option->id]) }}" class="m-0 d-inline">@csrf
                                <label class="az-switch" title="{{ $option->is_active?'Disable':'Enable' }} {{ $option->name }}">
                                    <input type="checkbox" {{ $option->is_active?'checked':'' }} onchange="this.form.submit()" aria-label="Toggle {{ $option->name }}"><span></span>
                                </label>
                            </form>
                        </td>
                        <td class="text-center"><div class="az-actions">
                            <button type="button" class="az-icon-btn az-edit" title="Edit {{ $option->name }}" data-bs-toggle="modal" data-bs-target="#azEditModal"
                                data-id="{{ $option->id }}" data-name="{{ $option->name }}" data-code="{{ $option->code }}"
                                data-manager-name="{{ $option->manager_name ?? '' }}" data-manager-phone="{{ $option->manager_phone ?? '' }}"
                                data-zone-id="{{ $option->service_zone_id ?? '' }}" data-sub-zone-id="{{ $option->service_sub_zone_id ?? '' }}"
                                data-district-id="{{ $option->service_district_id ?? '' }}" data-port-capacity="{{ $option->port_capacity ?? 0 }}"
                                data-ports-used="{{ $option->ports_used ?? 0 }}" data-latitude="{{ $option->latitude ?? '' }}" data-longitude="{{ $option->longitude ?? '' }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('company-settings.area-zone-setup.delete',['tab'=>$tab,'id'=>$option->id]) }}" class="m-0" onsubmit="return confirm('Delete {{ addslashes($option->name) }}? Linked records may prevent deletion.');">@csrf @method('DELETE')
                                <button type="submit" class="az-icon-btn az-delete" title="Delete {{ $option->name }}"><i class="bi bi-trash3"></i></button>
                            </form>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $tab==='zones'?8:($tab==='sub_zones'?7:($isBox?9:($tab==='districts'?6:6))) }}" class="az-empty">
                        <i class="bi bi-inbox"></i><strong>No {{ strtolower($tabs[$tab]) }} configured</strong><span>Add a new {{ strtolower($singular[$tab]) }} or change the search/filter.</span>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="az-table-footer"><span>Showing {{ $options->firstItem()??0 }}–{{ $options->lastItem()??0 }} of {{ $options->total() }}</span><div>{{ $options->onEachSide(1)->links() }}</div></div>
    </section>
</div>

<div class="modal fade az-modal" id="azAddModal" tabindex="-1" aria-labelledby="azAddModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="{{ route('company-settings.area-zone-setup.store') }}" id="azAddForm">@csrf
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="modal-header"><div><span class="az-kicker">AREA / ZONE MANAGEMENT</span><h5 class="modal-title" id="azAddModalLabel">Add {{ $singular[$tab] }}</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                @if($tab==='zones')
                    <label class="form-label">Zone Name</label><input name="name" class="form-control az-input mb-3" required maxlength="150" placeholder="Enter service zone name">
                    <label class="form-label">Zone Code</label><input name="code" class="form-control az-input mb-3" maxlength="60" placeholder="Optional code">
                    <label class="form-label">Lineman / Manager Name</label><input name="manager_name" class="form-control az-input mb-3" maxlength="150">
                    <label class="form-label">Contact Phone</label><input name="manager_phone" class="form-control az-input" maxlength="40">
                @elseif($tab==='sub_zones')
                    <label class="form-label">Service Zone</label><select name="service_zone_id" class="form-select az-input mb-3" required><option value="">Select Zone</option>@foreach($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}{{ !$zone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Sub-Zone Name</label><input name="name" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Sub-Zone Code</label><input name="code" class="form-control az-input" maxlength="60">
                @elseif($isBox)
                    <label class="form-label">Service Zone</label><select name="service_zone_id" class="form-select az-input mb-3 az-zone-select" required><option value="">Select Zone</option>@foreach($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}{{ !$zone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Sub-Zone (Optional)</label><select name="service_sub_zone_id" class="form-select az-input mb-3 az-subzone-select"><option value="">No Sub-Zone</option>@foreach($subZones as $subZone)<option value="{{ $subZone->id }}" data-zone="{{ $subZone->service_zone_id }}">{{ $subZone->name }}{{ !$subZone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Distribution Box Name</label><input name="name" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Box Code</label><input name="code" class="form-control az-input mb-3" required maxlength="60" pattern="[A-Za-z0-9_-]+">
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Port Capacity</label><input type="number" name="port_capacity" class="form-control az-input" min="1" max="1024" required></div><div class="col-6"><label class="form-label">Used Ports</label><input type="number" name="ports_used" class="form-control az-input" min="0" value="0" required></div></div>
                    <div class="row g-2"><div class="col-6"><label class="form-label">Latitude (Optional)</label><input type="number" step="any" name="latitude" class="form-control az-input"></div><div class="col-6"><label class="form-label">Longitude (Optional)</label><input type="number" step="any" name="longitude" class="form-control az-input"></div></div>
                @elseif($tab==='districts')
                    <label class="form-label">District Name</label><input name="name" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">District Code</label><input name="code" class="form-control az-input" maxlength="60">
                @else
                    <label class="form-label">District (Optional)</label><select name="service_district_id" class="form-select az-input mb-3"><option value="">Select District</option>@foreach($districts as $district)<option value="{{ $district->id }}">{{ $district->name }}{{ !$district->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Upazila Name</label><input name="name" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Upazila Code</label><input name="code" class="form-control az-input" maxlength="60">
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn az-secondary-btn" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn az-primary-btn"><i class="bi bi-plus-lg me-1"></i> Add {{ $singular[$tab] }}</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade az-modal" id="azEditModal" tabindex="-1" aria-labelledby="azEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" id="azEditForm">@csrf @method('PUT')
            <div class="modal-header"><div><span class="az-kicker">AREA / ZONE MANAGEMENT</span><h5 class="modal-title" id="azEditModalLabel">Edit {{ $singular[$tab] }}</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                @if($tab==='zones')
                    <label class="form-label">Zone Name</label><input name="name" id="azEditName" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Zone Code</label><input name="code" id="azEditCode" class="form-control az-input mb-3" maxlength="60">
                    <label class="form-label">Lineman / Manager Name</label><input name="manager_name" id="azEditManagerName" class="form-control az-input mb-3" maxlength="150">
                    <label class="form-label">Contact Phone</label><input name="manager_phone" id="azEditManagerPhone" class="form-control az-input" maxlength="40">
                @elseif($tab==='sub_zones')
                    <label class="form-label">Service Zone</label><select name="service_zone_id" id="azEditZoneId" class="form-select az-input mb-3" required><option value="">Select Zone</option>@foreach($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}{{ !$zone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Sub-Zone Name</label><input name="name" id="azEditName" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Sub-Zone Code</label><input name="code" id="azEditCode" class="form-control az-input" maxlength="60">
                @elseif($isBox)
                    <label class="form-label">Service Zone</label><select name="service_zone_id" id="azEditZoneId" class="form-select az-input mb-3 az-zone-select" required><option value="">Select Zone</option>@foreach($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}{{ !$zone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Sub-Zone (Optional)</label><select name="service_sub_zone_id" id="azEditSubZoneId" class="form-select az-input mb-3 az-subzone-select"><option value="">No Sub-Zone</option>@foreach($subZones as $subZone)<option value="{{ $subZone->id }}" data-zone="{{ $subZone->service_zone_id }}">{{ $subZone->name }}{{ !$subZone->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Distribution Box Name</label><input name="name" id="azEditName" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Box Code</label><input name="code" id="azEditCode" class="form-control az-input mb-3" required maxlength="60" pattern="[A-Za-z0-9_-]+">
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Port Capacity</label><input type="number" id="azEditCapacity" name="port_capacity" class="form-control az-input" min="1" max="1024" required></div><div class="col-6"><label class="form-label">Used Ports</label><input type="number" id="azEditUsed" name="ports_used" class="form-control az-input" min="0" required></div></div>
                    <div class="row g-2"><div class="col-6"><label class="form-label">Latitude</label><input type="number" step="any" id="azEditLatitude" name="latitude" class="form-control az-input"></div><div class="col-6"><label class="form-label">Longitude</label><input type="number" step="any" id="azEditLongitude" name="longitude" class="form-control az-input"></div></div>
                @elseif($tab==='districts')
                    <label class="form-label">District Name</label><input name="name" id="azEditName" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">District Code</label><input name="code" id="azEditCode" class="form-control az-input" maxlength="60">
                @else
                    <label class="form-label">District (Optional)</label><select name="service_district_id" id="azEditDistrictId" class="form-select az-input mb-3"><option value="">Select District</option>@foreach($districts as $district)<option value="{{ $district->id }}">{{ $district->name }}{{ !$district->is_active ? ' (Inactive)' : '' }}</option>@endforeach</select>
                    <label class="form-label">Upazila Name</label><input name="name" id="azEditName" class="form-control az-input mb-3" required maxlength="150">
                    <label class="form-label">Upazila Code</label><input name="code" id="azEditCode" class="form-control az-input" maxlength="60">
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn az-secondary-btn" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn az-primary-btn"><i class="bi bi-check2 me-1"></i> Save Changes</button></div>
        </form>
    </div></div>
</div>

<style>
.az-page{color:#e7edf5;padding-bottom:1.4rem}
.az-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem;min-height:56px;margin-bottom:14px}.az-breadcrumb{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:14px}.az-breadcrumb a{color:#8eacd0;text-decoration:none}.az-breadcrumb a:hover{color:#fff}.az-breadcrumb i{font-size:10px;color:#68798c}.az-breadcrumb strong{color:#f2f6fb}
.az-primary-btn{display:inline-flex;align-items:center;justify-content:center;background:#4da3f7;color:#071321;border:1px solid #5badff;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:650;white-space:nowrap}.az-primary-btn:hover{background:#72b7ff;color:#071321}.az-secondary-btn{background:#202b39;border:1px solid #354357;color:#dce6f1;border-radius:9px;padding:9px 16px}.az-alert{border-radius:10px;margin-bottom:14px}
.az-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.az-summary-card{display:flex;align-items:center;gap:14px;min-width:0;min-height:94px;padding:14px 16px;background:linear-gradient(145deg,#151d28,#111821);border:1px solid #283444;border-radius:15px}.az-icon{width:48px;height:48px;flex:0 0 48px;display:grid;place-items:center;border-radius:13px;font-size:22px}.az-blue{background:#18304a;color:#53b3ff}.az-green{background:#10342f;color:#10d5a6}.az-purple{background:#2d2042;color:#c17bff}.az-orange{background:#40301c;color:#ffbd57}.az-summary-copy{display:flex;flex-direction:column;min-width:0;line-height:1.25}.az-summary-copy>span{font-size:12px;color:#a8bad0;margin-bottom:3px}.az-summary-copy strong{font-size:22px;line-height:1.1;color:#fff}.az-summary-copy small{font-size:11px;color:#b8c5d5;margin-top:3px}
.az-tabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;background:#141b25;border:1px solid #283444;border-radius:14px;padding:8px;margin-bottom:14px}.az-tab{display:inline-flex;align-items:center;gap:9px;border-radius:10px;padding:10px 14px;color:#d5e0ed;text-decoration:none;font-size:13px;font-weight:650;white-space:nowrap}.az-tab i{font-size:16px}.az-tab b{font-size:11px;line-height:19px;min-width:20px;text-align:center;border-radius:7px;padding:0 5px;background:#222c3a;color:#d8e3f0}.az-tab.active{background:#4da3f7;color:#061625}.az-tab.active b{background:#79baff;color:#0a1c2e}.az-tab:hover:not(.active){background:#202d3d;color:#fff}
.az-data-card{background:#141b25;border:1px solid #2a3543;border-radius:14px;overflow:hidden}.az-card-heading{height:52px;display:flex;align-items:center;justify-content:space-between;padding:0 15px;border-bottom:1px solid #2a3543}.az-card-title{display:flex;align-items:center;gap:9px}.az-card-title i{color:#93a9c0}.az-card-title h2{font-size:15px;font-weight:700;margin:0;color:#f0f5fb}.az-count-pill{background:#222d3b;border-radius:8px;padding:5px 10px;color:#e1eaf4;font-size:11px;font-weight:650}
.az-toolbar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #2a3543}.az-search{height:40px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px;border:1px solid #364355;border-radius:9px;padding:0 12px;background:#0e141c;color:#93a8bf}.az-search input{width:100%;height:100%;border:0;outline:0;background:transparent;color:#eaf1f8;font-size:13px}.az-search input::placeholder{color:#7f91a7}.az-clear{color:#91a4ba}.az-status-filter{height:40px;min-width:138px;padding:0 34px 0 12px;border:1px solid #364355;border-radius:9px;background:#1a2431;color:#e5edf6;font-size:13px}
.az-table{--bs-table-bg:transparent;--bs-table-color:#dce5f0;--bs-table-border-color:#26313e;--bs-table-hover-bg:#192534;--bs-table-hover-color:#fff}.az-table thead th{height:42px;background:#1a222e;color:#cbd7e5;font-size:11px;font-weight:750;letter-spacing:.025em;white-space:nowrap;border-bottom:1px solid #344153;padding:10px 12px}.az-table tbody td{height:48px;padding:8px 12px;border-bottom:1px solid #25303c;font-size:13px;color:#d9e3ef}.az-table tbody tr:last-child td{border-bottom:0}.az-table tbody tr:hover{--bs-table-bg:transparent;background:#192431}.az-sl{width:55px;text-align:center}.az-row-number{color:#98abc0!important;text-align:center}.az-code{display:inline-block;background:#202a37;color:#9fbce0;border-radius:5px;padding:3px 7px;font-size:11px}.az-muted{color:#8fa3ba}.az-manager{font-weight:600}.az-status-col{width:105px}.az-action-col{width:105px}
.az-switch{display:inline-block;position:relative;width:39px;height:21px;vertical-align:middle;cursor:pointer}.az-switch input{opacity:0;width:0;height:0}.az-switch span{position:absolute;inset:0;background:#465363;border-radius:20px;transition:.18s}.az-switch span:before{content:"";position:absolute;left:3px;top:3px;width:15px;height:15px;background:#eaf0f7;border-radius:50%;transition:.18s}.az-switch input:checked+span{background:#4da3f7}.az-switch input:checked+span:before{transform:translateX(18px);background:#fff}.az-switch input:focus-visible+span{outline:2px solid #9bcfff;outline-offset:2px}
.az-actions{display:flex;align-items:center;justify-content:center;gap:8px}.az-icon-btn{width:28px;height:28px;display:inline-grid;place-items:center;background:transparent;border:0;border-radius:6px;font-size:14px}.az-edit{color:#e0e9f4}.az-edit:hover{background:#26364a;color:#66b4ff}.az-delete{color:#e44c5b}.az-delete:hover{background:#3b232a;color:#ff7784}
.az-empty{text-align:center!important;padding:38px 14px!important;color:#8ea1b6!important}.az-empty i{display:block;font-size:25px;margin-bottom:8px}.az-empty strong,.az-empty span{display:block}.az-empty strong{color:#dae5f0;margin-bottom:3px}.az-empty span{font-size:12px}
.az-table-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:52px;padding:8px 14px;color:#9badc1;font-size:12px;border-top:1px solid #283444}.az-table-footer .pagination{margin:0;gap:5px}.az-table-footer .page-link{border:1px solid #344153!important;background:#1a2431!important;color:#cbd9e8!important;border-radius:8px!important;font-size:12px;padding:6px 10px}.az-table-footer .page-item.active .page-link{background:#4da3f7!important;border-color:#4da3f7!important;color:#071727!important}
.az-modal .modal-content{background:#151e29;border:1px solid #354355;border-radius:14px;color:#e7eef6;box-shadow:0 18px 60px #0008}.az-modal .modal-header,.az-modal .modal-footer{border-color:#2c3948;padding:16px 19px}.az-modal .modal-body{padding:18px 19px}.az-modal .form-label{font-size:12px;font-weight:650;color:#c8d6e5;margin-bottom:6px}.az-kicker{display:block;font-size:9px;letter-spacing:.1em;color:#68b5ff;font-weight:750;margin-bottom:4px}.az-modal .modal-title{font-size:17px;font-weight:700}.az-input{background:#0e151e;border:1px solid #354457;color:#eef4fb;border-radius:8px;font-size:13px}.az-input:focus{background:#0e151e;border-color:#4da3f7;color:#fff;box-shadow:0 0 0 .18rem #4da3f722}.az-input option{background:#151e29;color:#eef4fb}
@media(max-width:1000px){.az-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.az-heading{align-items:flex-start;flex-direction:column;padding:8px 0}.az-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.az-summary-card{min-height:78px;padding:10px;gap:9px}.az-icon{width:39px;height:39px;flex-basis:39px;font-size:18px}.az-summary-copy>span{font-size:10px}.az-summary-copy strong{font-size:19px}.az-tabs{overflow-x:auto;flex-wrap:nowrap}.az-tab{padding:9px 11px;font-size:12px}.az-toolbar{align-items:stretch;flex-direction:column}.az-status-filter{width:100%}.az-table{min-width:780px}.az-table-footer{align-items:flex-start;flex-direction:column}.az-breadcrumb{gap:7px;font-size:12px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const tab='{{ $tab }}';
    const editModal=document.getElementById('azEditModal');
    const editForm=document.getElementById('azEditForm');
    const editBase='{{ $editBase }}';
    const setValue=(id,value)=>{const el=document.getElementById(id);if(el)el.value=value??'';};
    function filterSubZones(zoneSelect,subZoneSelect,selected){
        if(!zoneSelect||!subZoneSelect)return;
        const zone=zoneSelect.value;
        Array.from(subZoneSelect.options).forEach(option=>{
            if(!option.value){option.hidden=false;return;}
            option.hidden=option.dataset.zone!==zone;
        });
        if(selected!==undefined)subZoneSelect.value=selected;
        else if(subZoneSelect.selectedOptions[0]?.hidden)subZoneSelect.value='';
    }
    document.querySelectorAll('.az-zone-select').forEach(zone=>{
        const sub=zone.closest('form')?.querySelector('.az-subzone-select');
        zone.addEventListener('change',()=>filterSubZones(zone,sub));
    });
    if(editModal){
        editModal.addEventListener('show.bs.modal',function(event){
            const trigger=event.relatedTarget;if(!trigger)return;
            editForm.action=editBase.replace('999999999',trigger.dataset.id);
            setValue('azEditName',trigger.dataset.name);
            setValue('azEditCode',trigger.dataset.code);
            setValue('azEditManagerName',trigger.dataset.managerName);
            setValue('azEditManagerPhone',trigger.dataset.managerPhone);
            setValue('azEditZoneId',trigger.dataset.zoneId);
            setValue('azEditDistrictId',trigger.dataset.districtId);
            setValue('azEditCapacity',trigger.dataset.portCapacity);
            setValue('azEditUsed',trigger.dataset.portsUsed);
            setValue('azEditLatitude',trigger.dataset.latitude);
            setValue('azEditLongitude',trigger.dataset.longitude);
            const zone=document.getElementById('azEditZoneId');
            const sub=document.getElementById('azEditSubZoneId');
            if(zone&&sub){filterSubZones(zone,sub,trigger.dataset.subZoneId);zone.addEventListener('change',()=>filterSubZones(zone,sub),{once:true});}
        });
    }
    const search=document.querySelector('#azFilterForm input[name="q"]');
    if(search){let timer;search.addEventListener('input',()=>{window.clearTimeout(timer);timer=window.setTimeout(()=>document.getElementById('azFilterForm').submit(),450);});}
});
</script>
</x-app-layout>
