@push('styles')
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-monitoring-polish.css') }}">
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-map-polish.css') }}">
@endpush
<div class="container-fluid py-3 network-map-page">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h3 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Network Map</h3><small class="text-muted">Geographic view of routers, OLT/ONU devices, access points and mapped customers</small></div><div class="d-flex align-items-center gap-2"><span class="badge bg-success">{{ $routers->where('action','connected')->count() }} routers online</span><a class="btn btn-sm btn-outline-primary" href="{{ route('network-topology.live') }}"><i class="bi bi-diagram-3 me-1"></i>Live Topology</a></div></div>
<div class="row g-3 mb-3">
<div class="col-md-3"><div class="card map-stat-card"><div class="card-body"><small>Active Customers</small><h4>{{ number_format($customers) }}</h4><span class="text-muted small">Billing accounts</span></div></div></div>
<div class="col-md-3"><div class="card map-stat-card"><div class="card-body"><small>Mapped Nodes</small><h4>{{ number_format($nodes->count()) }}</h4><span class="text-muted small">With valid coordinates</span></div></div></div>
<div class="col-md-6"><div class="card map-stat-card"><div class="card-body"><div class="map-device-legend"><span><i class="map-legend-dot router"></i>MikroTik</span><span><i class="map-legend-dot olt"></i>OLT</span><span><i class="map-legend-dot onu"></i>ONU</span><span><i class="map-legend-dot wifi-router"></i>WiFi</span><span><i class="map-legend-dot access-point"></i>AP</span><span><i class="map-legend-dot customer"></i>Customer</span></div></div></div></div>
</div>
<div class="card"><div class="card-body">
<div class="row g-2 mb-3">
<div class="col-lg-3 col-md-6"><label class="form-label">Device / Network Type</label><select id="map-type-filter" class="form-select"><option value="">All Devices</option><option value="router">MikroTik Router</option><option value="olt">OLT</option><option value="onu">ONU</option><option value="wifi-router">WiFi Router</option><option value="access-point">Access Point</option><option value="switch">Switch</option><option value="customer">Customer</option></select></div>
<div class="col-lg-3 col-md-6"><label class="form-label">Router</label><select id="map-router-filter" class="form-select"><option value="">All Routers</option>@foreach($routers as $r)<option value="{{ $r->router_name }}">{{ $r->router_name }}</option>@endforeach</select></div>
<div class="col-lg-2 col-md-6"><label class="form-label">Status</label><select id="map-status-filter" class="form-select"><option value="">All Status</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Unknown</option></select></div>
<div class="col-lg-4 col-md-6"><label class="form-label">Search</label><div class="input-group"><input id="map-customer-search" class="form-control" placeholder="Name, IP, ID or location"><button id="map-reset" class="btn btn-outline-secondary" type="button" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></button></div></div>
</div>
<div class="map-layer-toolbar">
    <div class="btn-group map-basemap-switcher" role="group" aria-label="Map style">
        <button id="map-base-street" class="btn btn-sm active" type="button" aria-pressed="true"><i class="bi bi-signpost-2 me-1"></i>Street</button>
        <button id="map-base-satellite" class="btn btn-sm" type="button" aria-pressed="false"><i class="bi bi-globe-asia-australia me-1"></i>Satellite</button>
        <button id="map-base-dark" class="btn btn-sm" type="button" aria-pressed="false"><i class="bi bi-moon-stars me-1"></i>Dark Map</button>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button id="map-pitch-toggle" class="btn btn-sm btn-outline-secondary" type="button" aria-pressed="false"><i class="bi bi-boxes me-1"></i>3D view</button>
        <button id="map-fit" class="btn btn-sm btn-outline-primary" type="button" title="Fit all visible markers"><i class="bi bi-bounding-box me-1"></i>Fit markers</button>
    </div>
</div>
<div class="map-streetview-tip"><i class="bi bi-info-circle"></i><span>Click a marker to view device details. Use <strong>Street View</strong> in its popup to open Google imagery where coverage is available.</span><span id="map-result-count" class="map-result-count"></span></div>
<div id="network-map" class="network-map-canvas" wire:ignore aria-label="Interactive MapLibre network map">
    <div id="map-loading-state" class="map-loading-state"><span class="map-loading-state__spinner" aria-hidden="true"></span><strong>Loading vector map…</strong><span>Roads, place labels and network markers</span></div>
</div>
<div class="network-map-footer"><span><i class="bi bi-info-circle me-1"></i>Device positions and connections use saved coordinates. Base maps are provided by OpenFreeMap and Esri; Street View opens Google Maps in a new tab.</span><span>Zoom with scroll or + / − · drag to pan</span></div>
</div></div>
</div>
@push('scripts')
<script>
window.XLINK_NETWORK_MAP_DATA = {
    nodes: @json($nodes),
    edges: @json($mapEdges),
};
</script>
@vite('resources/js/network-map.js')
@endpush
