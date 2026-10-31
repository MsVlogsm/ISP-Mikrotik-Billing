<x-app-layout>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h2 class="mb-1">Planning & Serviceability</h2><p class="text-muted mb-0">Topology maps, mapped network assets, ports and link capacity maintained in X-Link.</p></div>
        <div class="d-flex flex-wrap gap-2"><a href="{{ route('network-topology.designer') }}" class="btn btn-primary">Open Topology Designer</a><a href="{{ route('network-map') }}" class="btn btn-outline-secondary">Open Network Map</a></div>
    </div>
    <div class="row g-3 mb-4">
        @foreach([['Topology Maps',$stats['maps']],['Nodes on Selected Map',$stats['nodes']],['Nodes with Coordinates',$stats['located_nodes']],['Published Nodes',$stats['published_nodes']],['Links',$stats['links']],['Published Links',$stats['published_links']]] as [$label,$value])
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="small text-muted">{{ $label }}</div><div class="fs-3 fw-semibold">{{ number_format($value) }}</div></div></div></div>
        @endforeach
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Select Planning Map</div>
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <form method="get" action="{{ route('ipilot.gis.planning') }}" class="d-flex flex-wrap gap-2">
                <select class="form-select" name="map" aria-label="Topology map">
                    @foreach($maps as $item)<option value="{{ $item->id }}" @selected($map && $map->id===$item->id)>{{ $item->name }}{{ $item->is_default ? ' (Default)' : '' }}</option>@endforeach
                </select>
                <button class="btn btn-outline-primary">Load Map</button>
            </form>
            @if($map)<div class="small text-muted">{{ $map->description }}</div>@endif
        </div>
    </div>
    @if($map)
    <div class="row g-4">
        <div class="col-xl-6"><div class="card h-100"><div class="card-header fw-semibold">Topology Nodes</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Asset</th><th>Type</th><th>Ports / Capacity</th><th>Coordinates</th><th>Published</th></tr></thead><tbody>
            @forelse($nodes as $node)<tr><td><strong>{{ $node->name }}</strong><div class="small text-muted">{{ $node->location }}</div></td><td>{{ $node->type }}{{ $node->subtype ? ' / '.$node->subtype : '' }}</td><td>{{ $node->input_ports ?? '—' }} in / {{ $node->output_ports ?? '—' }} out<div class="small text-muted">{{ $node->port_capacity ?? '—' }} capacity</div></td><td>{{ $node->latitude !== null && $node->longitude !== null ? $node->latitude.', '.$node->longitude : 'Not mapped' }}</td><td>{{ $node->is_published ? 'Yes' : 'Draft' }}</td></tr>
            @empty<tr><td colspan="5" class="text-center text-muted py-3">No nodes have been added to this map.</td></tr>@endforelse
        </tbody></table></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header fw-semibold">Topology Links & Service Capacity</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Link</th><th>Type</th><th>Capacity</th><th>Traffic</th><th>Status</th></tr></thead><tbody>
            @forelse($links as $link)<tr><td><strong>{{ $link->label ?: $link->source_key.' → '.$link->target_key }}</strong><div class="small text-muted">{{ $link->source_port ?: '—' }} → {{ $link->target_port ?: '—' }}</div></td><td>{{ $link->connection_type }}</td><td>{{ $link->capacity_mbps !== null ? number_format($link->capacity_mbps).' Mbps' : 'Not set' }}</td><td>{{ $link->traffic_mbps !== null ? number_format($link->traffic_mbps).' Mbps (manual/saved)' : 'Not measured' }}</td><td>{{ $link->status ?: 'Unknown' }}</td></tr>
            @empty<tr><td colspan="5" class="text-center text-muted py-3">No links have been added to this map.</td></tr>@endforelse
        </tbody></table></div></div></div>
    </div>
    @endif
    <div class="alert alert-warning mt-4 mb-0">This page reports saved topology and capacity metadata. Automatic serviceability polygons, area coverage predictions and real-time link traffic are not calculated unless those underlying measurements are explicitly configured.</div>
</div>
</x-app-layout>
