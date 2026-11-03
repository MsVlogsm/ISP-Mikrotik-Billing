@push('styles')
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-monitoring-polish.css') }}">
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-map-polish.css') }}">
@endpush
@php
    $mapKindLabels = [
        'router' => 'MikroTik', 'olt' => 'OLT', 'onu' => 'ONU', 'wifi-router' => 'WiFi Router',
        'access-point' => 'Access Point', 'switch' => 'Switch', 'customer' => 'Customer',
    ];
@endphp
<div class="container-fluid py-3 network-map-page network-map-command-center">
    <header class="network-map-hero mb-3">
        <div class="network-map-hero__identity">
            <div class="network-map-hero__icon"><i class="bi bi-globe2"></i></div>
            <div>
                <div class="network-map-eyebrow">X-LINK NETWORK OPERATIONS</div>
                <h3 class="mb-1">Network Map <span class="network-map-title-badge">Spatial intelligence</span></h3>
                <p class="mb-0">Explore infrastructure, customer locations, availability and published connections in one view.</p>
            </div>
        </div>
        <div class="network-map-hero__actions">
            <div class="network-map-snapshot"><span class="network-map-live-dot"></span><span>Snapshot generated</span><strong>{{ $mapStats['generated_at'] }}</strong></div>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('network-map') }}"><i class="bi bi-arrow-clockwise me-1"></i>Refresh Map</a>
            <a class="btn btn-sm btn-outline-success" href="{{ route('optical-audit') }}"><i class="bi bi-activity me-1"></i>Virtual OTDR</a>
            <a class="btn btn-sm btn-primary" href="{{ route('network-topology.live') }}"><i class="bi bi-diagram-3 me-1"></i>Live Topology</a>
        </div>
    </header>

    <section class="card network-map-fiber-hub mb-3" aria-label="Fiber mapping workspace">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-xl-4">
                    <span class="network-map-section-kicker">FIBER FIELD REGISTER</span>
                    <h6 class="mb-1">Fiber topology &amp; customer mapping</h6>
                    <p class="small text-muted mb-0">Manage physical cable records, OLT/PON uplinks, splice closures, splitter ports and verified ONU-to-customer assignments.</p>
                </div>
                <div class="col-xl-5">
                    <div class="row g-2 text-center">
                        <div class="col-3"><div class="network-map-fiber-metric"><strong>{{ number_format($fiberStats['cables']) }}</strong><span>Cables</span><small>{{ number_format($fiberStats['verified_cables']) }} verified</small></div></div>
                        <div class="col-3"><div class="network-map-fiber-metric"><strong>{{ number_format($fiberStats['splitters']) }}</strong><span>Splitters</span><small>{{ number_format($fiberStats['closures']) }} closures</small></div></div>
                        <div class="col-3"><div class="network-map-fiber-metric"><strong>{{ number_format($fiberStats['splices']) }}</strong><span>Splices</span><small>{{ number_format($fiberStats['verified_splices']) }} verified</small></div></div>
                        <div class="col-3"><div class="network-map-fiber-metric"><strong>{{ number_format($fiberStats['verified_customer_mappings']) }} / {{ number_format($fiberStats['customer_mappings']) }}</strong><span>ONU mappings</span><small>{{ number_format($fiberStats['pending_records']) }} pending records</small></div></div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="d-flex flex-wrap justify-content-xl-end gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('network-topology.designer') }}"><i class="bi bi-bezier2 me-1"></i>Topology Designer</a>
                        <a class="btn btn-sm btn-primary" href="{{ route('fiber-mapping') }}"><i class="bi bi-diagram-3 me-1"></i>Manual Fiber Mapping</a>
                    </div>
                    <div class="small text-muted text-xl-end mt-2">Unverified field records are not treated as confirmed physical paths.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-3 network-map-kpis" aria-label="Network map overview">
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-total"><div class="network-map-kpi__top"><span>Mapped nodes</span><i class="bi bi-geo-alt"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['total_nodes']) }}</div><div class="network-map-kpi__foot">Saved coordinates</div></article></div>
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-online"><div class="network-map-kpi__top"><span>Online</span><i class="bi bi-check-circle"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['online_nodes']) }}</div><div class="network-map-kpi__foot">{{ $mapStats['total_nodes'] ? round($mapStats['online_nodes'] / $mapStats['total_nodes'] * 100) : 0 }}% of mapped nodes</div></article></div>
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-offline"><div class="network-map-kpi__top"><span>Offline</span><i class="bi bi-exclamation-circle"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['offline_nodes']) }}</div><div class="network-map-kpi__foot">Review availability</div></article></div>
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-unknown"><div class="network-map-kpi__top"><span>Unknown status</span><i class="bi bi-question-circle"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['unknown_nodes']) }}</div><div class="network-map-kpi__foot">No clear status value</div></article></div>
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-customer"><div class="network-map-kpi__top"><span>Located customers</span><i class="bi bi-people"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['located_customers']) }}</div><div class="network-map-kpi__foot">Of {{ number_format($mapStats['active_customers']) }} active accounts</div></article></div>
        <div class="col-6 col-md-4 col-xl-2"><article class="network-map-kpi kpi-gap"><div class="network-map-kpi__top"><span>Missing location</span><i class="bi bi-pin-map"></i></div><div class="network-map-kpi__value">{{ number_format($mapStats['unlocated_customers']) }}</div><div class="network-map-kpi__foot">Accounts without valid GPS coordinates</div></article></div>
    </section>

    <details class="network-map-overview-disclosure mb-3">
        <summary>
            <span><i class="bi bi-layers me-2"></i>Inventory layers &amp; data coverage</span>
            <small>Show counts, customer GPS coverage and missing locations</small>
        </summary>
        <div class="network-map-overview row g-3 mt-2">
        <div class="col-xl-7"><div class="network-map-info-card h-100"><div class="network-map-info-card__heading"><div><span class="network-map-section-kicker">INVENTORY LAYERS</span><h6 class="mb-0">What's on the map</h6></div><span class="network-map-muted-pill">{{ number_format($mapStats['total_nodes']) }} geolocated records</span></div>
            <div class="map-device-legend network-map-layer-legend">
                @foreach($mapKindLabels as $kind => $label)
                    @if(($mapKindCounts[$kind] ?? 0) > 0)
                        <span class="network-map-layer-chip"><i class="map-legend-dot {{ $kind }}"></i>{{ $label }} <strong>{{ number_format($mapKindCounts[$kind]) }}</strong></span>
                    @endif
                @endforeach
            </div>
        </div></div>
        <div class="col-xl-5"><div class="network-map-info-card h-100"><div class="network-map-info-card__heading"><div><span class="network-map-section-kicker">DATA COMPLETENESS</span><h6 class="mb-0">Customer location coverage</h6></div><strong class="network-map-coverage-value">{{ $mapStats['customer_coverage_percent'] }}%</strong></div>
            <div class="progress network-map-coverage-progress" role="progressbar" aria-label="Customer coordinate coverage" aria-valuenow="{{ $mapStats['customer_coverage_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: {{ $mapStats['customer_coverage_percent'] }}%"></div></div>
            <div class="network-map-coverage-foot"><span>{{ number_format($mapStats['located_customers']) }} located</span><span>{{ number_format($mapStats['unlocated_customers']) }} need coordinates</span></div>
            <div class="network-map-inline-metrics"><span><i class="bi bi-router"></i>Router coordinates missing <strong>{{ number_format($mapStats['unlocated_routers']) }}</strong></span><span><i class="bi bi-hdd-network"></i>Device coordinates missing <strong>{{ number_format($mapStats['unlocated_devices']) }}</strong></span></div>
        </div></div>
        </div>
    </details>

    <section class="card network-map-workspace">
        <div class="card-header network-map-workspace__header">
            <div><span class="network-map-section-kicker">GEOGRAPHIC OPERATIONS</span><h5 class="mb-0">Explore network locations</h5><small>Filter inventory, inspect node health and follow mapped connections.</small></div>
            <div class="network-map-workspace__header-badges"><span><i class="bi bi-diagram-2"></i>{{ number_format($mapStats['mapped_connections']) }} mapped links</span><span><i class="bi bi-hdd-network"></i>{{ number_format($mapStats['located_routers'] + $mapStats['located_devices']) }} located infrastructure nodes</span></div>
        </div>
        <div class="card-body">
            <div class="network-map-filterbar mb-3">
                <div class="network-map-filterbar__search">
                    <label class="form-label" for="map-customer-search">Find a customer or network device</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input id="map-customer-search" class="form-control" placeholder="Search name, customer ID, IP or location…" autocomplete="off" aria-label="Search network map">
                        <button id="map-reset" class="btn btn-outline-secondary" type="button" title="Reset search and filters" aria-label="Reset search and filters"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                </div>
                <details class="network-map-filter-drawer">
                    <summary><i class="bi bi-funnel me-2"></i>More filters <span>Type · Router · Status</span></summary>
                    <div class="row g-2 network-map-filters">
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="map-type-filter">Device / network type</label>
                            <select id="map-type-filter" class="form-select">
                                <option value="">All types</option><option value="router">MikroTik Router</option><option value="olt">OLT</option><option value="onu">ONU</option><option value="wifi-router">WiFi Router</option><option value="access-point">Access Point</option><option value="switch">Switch</option><option value="customer">Customer</option><option value="splitter">Fiber Splitter</option><option value="olt_pon">OLT PON Port</option><option value="odf">ODF</option><option value="rack">Rack</option><option value="pop">POP</option><option value="fiber_segment">Fiber Segment</option><option value="cabinet">Street Cabinet</option><option value="pole">Utility Pole</option><option value="splice">Splice Closure</option><option value="server">Server / Headend</option>
                            </select>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="map-router-filter">Router</label>
                            <select id="map-router-filter" class="form-select"><option value="">All routers</option>@foreach($routers as $r)<option value="{{ $r->router_name }}">{{ $r->router_name }}</option>@endforeach</select>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="map-status-filter">Status</label>
                            <select id="map-status-filter" class="form-select"><option value="">All status</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Unknown</option></select>
                        </div>
                    </div>
                </details>
            </div>

            <div class="map-layer-toolbar network-map-toolbar">
                <div class="network-map-toolbar__main">
                    <div class="btn-group map-basemap-switcher" role="group" aria-label="Map style">
                        <button id="map-base-street" class="btn btn-sm active" type="button" aria-pressed="true"><i class="bi bi-signpost-2 me-1"></i>Street</button>
                        <button id="map-base-satellite" class="btn btn-sm" type="button" aria-pressed="false"><i class="bi bi-globe-asia-australia me-1"></i>Satellite</button>
                        <button id="map-base-dark" class="btn btn-sm" type="button" aria-pressed="false"><i class="bi bi-moon-stars me-1"></i>Dark</button>
                    </div>
                    <div class="network-map-layer-toggles" role="group" aria-label="Visible map layers">
                        <button id="map-toggle-links" class="network-map-layer-toggle active" type="button" aria-pressed="true" title="Show or hide physical and logical network links"><i class="bi bi-bezier2"></i><span>Connections</span></button>
                        <button id="map-toggle-customers" class="network-map-layer-toggle active" type="button" aria-pressed="true" title="Show or hide customer markers"><i class="bi bi-people"></i><span>Customers</span></button>
                        <button id="map-toggle-labels" class="network-map-layer-toggle active" type="button" aria-pressed="true" title="Show or hide node labels when zoomed in"><i class="bi bi-tag"></i><span>Labels</span></button>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button id="map-pitch-toggle" class="btn btn-sm btn-outline-secondary" type="button" aria-pressed="false"><i class="bi bi-boxes me-1"></i>3D view</button>
                    <button id="map-fit" class="btn btn-sm btn-outline-primary" type="button" title="Fit all visible markers"><i class="bi bi-bounding-box me-1"></i>Fit visible</button>
                </div>
            </div>

            <div class="map-streetview-tip network-map-hint"><i class="bi bi-info-circle"></i><span>Select a marker or a node in the directory to inspect details. Connection lines open capacity, latency and packet-loss data when available.</span><span id="map-result-count" class="map-result-count">{{ number_format($mapStats['total_nodes']) }} nodes</span></div>

            <div class="row g-3 network-map-layout">
                <div class="col-xl-9 network-map-layout__main">
                    <div class="network-map-canvas-heading"><span><i class="bi bi-map me-2"></i>Geographic canvas</span><span><i class="bi bi-mouse me-1"></i>Scroll to zoom · drag to pan</span></div>
                    <div id="network-map" class="network-map-canvas" wire:ignore aria-label="Interactive MapLibre network map">
                        <div id="map-loading-state" class="map-loading-state"><span class="map-loading-state__spinner" aria-hidden="true"></span><strong>Loading vector map…</strong><span>Roads, place labels and network markers</span></div>
                    </div>
                    <div class="network-map-footer"><span><i class="bi bi-info-circle me-1"></i>Basemaps: OpenFreeMap / Esri imagery. Street View opens Google Maps when coverage is available.</span><span>Map coordinates come from saved records.</span></div>
                </div>
                <aside class="col-xl-3 network-map-layout__aside" aria-label="Live map insights">
                    <section class="network-map-side-card network-map-view-health">
                        <div class="network-map-side-card__heading"><div><span class="network-map-section-kicker">CURRENT VIEW</span><h6 class="mb-0">Visible inventory</h6></div><i class="bi bi-activity"></i></div>
                        <div class="network-map-view-total"><strong id="map-visible-nodes">{{ number_format($mapStats['total_nodes']) }}</strong><span>nodes match current filters</span></div>
                        <div class="network-map-view-status"><span class="status-dot online"></span><span>Online</span><strong id="map-visible-online">{{ number_format($mapStats['online_nodes']) }}</strong></div>
                        <div class="network-map-view-status"><span class="status-dot offline"></span><span>Offline</span><strong id="map-visible-offline">{{ number_format($mapStats['offline_nodes']) }}</strong></div>
                        <div class="network-map-view-status"><span class="status-dot unknown"></span><span>Unknown</span><strong id="map-visible-unknown">{{ number_format($mapStats['unknown_nodes']) }}</strong></div>
                        <div class="network-map-view-status network-map-view-status--links"><i class="bi bi-diagram-2"></i><span>Visible links</span><strong id="map-visible-links">{{ number_format($mapStats['mapped_connections']) }}</strong></div>
                    </section>
                    <section class="network-map-side-card network-map-directory-card">
                        <div class="network-map-side-card__heading"><div><span class="network-map-section-kicker">NODE DIRECTORY</span><h6 class="mb-0">Nodes to inspect</h6></div><span class="network-map-directory-count" id="map-directory-count">{{ min(10, $mapStats['total_nodes']) }}</span></div>
                        <p class="network-map-side-note">Offline and unknown nodes are prioritized. Search to find a specific customer or device.</p>
                        <div id="map-node-directory" class="network-map-node-directory" aria-live="polite"><div class="network-map-directory-empty">Preparing node list…</div></div>
                        <div id="map-directory-summary" class="network-map-directory-summary">Showing up to 10 matching nodes</div>
                    </section>
                </aside>
            </div>
        </div>
    </section>

    <section class="row g-3 mt-1 network-map-integrity">
        <div class="col-lg-4"><article class="network-map-info-card h-100"><div class="network-map-info-card__heading"><div><span class="network-map-section-kicker">LOCATION GAPS</span><h6 class="mb-0">Records not placed on map</h6></div><i class="bi bi-pin-map network-map-insight-icon"></i></div><div class="network-map-gap-row"><span>Customers</span><strong>{{ number_format($mapStats['unlocated_customers']) }}</strong></div><div class="network-map-gap-row"><span>MikroTik routers</span><strong>{{ number_format($mapStats['unlocated_routers']) }}</strong></div><div class="network-map-gap-row"><span>Inventory devices</span><strong>{{ number_format($mapStats['unlocated_devices']) }}</strong></div><small class="network-map-side-note">Add valid latitude/longitude to include these records in geographic views. Disabled and active accounts are included; deleted records are excluded.</small>
            @if($unlocatedCustomerRecords->isNotEmpty())
                <details class="network-map-gps-queue mt-3">
                    <summary><i class="bi bi-list-check me-1"></i>Open GPS completion queue <strong>{{ $unlocatedCustomerRecords->count() }}</strong></summary>
                    <div class="network-map-gps-queue__list">
                        @foreach($unlocatedCustomerRecords as $missingCustomer)
                            <div class="network-map-gps-queue__item">
                                <div class="min-w-0">
                                    <strong>{{ $missingCustomer->customer_name ?: 'Unnamed customer' }}</strong>
                                    <small>{{ $missingCustomer->customer_unique_id }}</small>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('customers.edit', encrypt($missingCustomer->customer_unique_id)) }}" target="_blank" rel="noopener">
                                    <i class="bi bi-geo-alt me-1"></i>Add GPS
                                </a>
                            </div>
                        @endforeach
                    </div>
                    <small class="network-map-side-note d-block mt-2">Customer Edit → Network Location → click the actual location on the map → Save Changes.</small>
                </details>
            @endif
            </article></div>
        <div class="col-lg-4"><article class="network-map-info-card h-100"><div class="network-map-info-card__heading"><div><span class="network-map-section-kicker">CONNECTION COVERAGE</span><h6 class="mb-0">Published topology links</h6></div><i class="bi bi-bezier2 network-map-insight-icon"></i></div><div class="network-map-connection-coverage"><div><strong>{{ number_format($mapStats['mapped_connections']) }}</strong><span>drawable links</span></div><div><strong>{{ number_format($mapStats['unmapped_connections']) }}</strong><span>missing endpoint coordinates</span></div></div><div class="network-map-coverage-foot"><span>{{ number_format($mapStats['published_connections']) }} published in total</span><a href="{{ route('network-topology.live') }}">Manage topology <i class="bi bi-arrow-up-right"></i></a></div></article></div>
        <div class="col-lg-4"><article class="network-map-info-card h-100"><div class="network-map-info-card__heading"><div><span class="network-map-section-kicker">MAP CONTROLS</span><h6 class="mb-0">Explore efficiently</h6></div><i class="bi bi-compass network-map-insight-icon"></i></div><div class="network-map-tip-row"><i class="bi bi-search"></i><span>Filter by type, router or status; search also matches IP, customer ID and location.</span></div><div class="network-map-tip-row"><i class="bi bi-bounding-box"></i><span>Use <strong>Fit visible</strong> to frame the currently filtered markers.</span></div><div class="network-map-tip-row"><i class="bi bi-broadcast"></i><span>Dashed amber lines represent logical services; solid lines represent physical connection types.</span></div></article></div>
    </section>
</div>
@push('scripts')
<script>
window.XLINK_NETWORK_MAP_DATA = {
    nodes: @json($nodes),
    edges: @json($mapEdges),
    stats: @json($mapStats),
    config: @json($mapConfig),
    tools: {
        designer: @json(route('network-topology.designer')),
        live: @json(route('network-topology.live')),
        fiber: @json(route('fiber-mapping')),
        history: @json(route('network-inventory.health-history')),
    },
};
</script>
@vite('resources/js/network-map.js')
@endpush
