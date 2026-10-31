<x-app-layout>
<div class="container-fluid py-3">
 <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><span class="badge bg-primary-subtle text-primary mb-2">X-Link Limited</span><h2 class="mb-1">iPilot Operations Workspace</h2><p class="text-muted mb-0">Unified access to network operations, integrations, reporting and read-only diagnostics.</p></div>
  <a class="btn btn-outline-primary" href="{{ route('ai-engineer') }}"><i class="bi bi-stars me-1"></i>AI Engineer</a>
 </div>
 <div class="row g-3 mb-4">
  @foreach([['Customers',$stats['customers'],'bi-people'],['Active Customers',$stats['active_customers'],'bi-person-check'],['Routers',$stats['routers'],'bi-router'],['Routers Connected',$stats['routers_online'],'bi-wifi'],['OLTs',$stats['olts'],'bi-hdd-network'],['ONU Mappings',$stats['onu_mappings'],'bi-diagram-3']] as [$label,$value,$icon])
  <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-semibold">{{ number_format($value) }}</div><i class="bi {{ $icon }} text-primary"></i></div></div></div>
  @endforeach
 </div>
 <div class="row g-3">
  @php($sections=[
   ['Geographic Network Map','Geographic network inventory using existing verified device/customer coordinates.','network-map','bi-geo-alt','Open map'],
   ['Custom Map Editor','Create and publish fiber assets, ports, and physical route relationships.','network-topology.designer','bi-diagram-3','Edit custom map'],
   ['Auto Network Map','Live inventory relationships across routers, OLTs, PON branches, ONUs and mapped customers.','network-topology.live','bi-bezier2','View auto map'],
   ['Integration Center','Create scoped API clients, signed webhooks and review delivery history.','ipilot.integrations','bi-plug','Manage integrations'],
   ['Zabbix Servers','Configure encrypted API tokens and test read-only monitoring.','ipilot.zabbix','bi-activity','Manage Zabbix'],
   ['Application Settings','Currency, GIS provider, operational preferences, notifications and media endpoints.','ipilot.app-settings','bi-sliders','Open settings'],
   ['Network & Outside Plant','Verified device inventory, known locations and outside-plant records.','ipilot.gis.outside-plant','bi-hdd-network','View assets'],
   ['Network Operations','Recorded network events and background-sync health.','ipilot.gis.operations','bi-activity','View operations'],
   ['Planning & Serviceability','Topology maps, mapped nodes, links and saved service capacity.','ipilot.gis.planning','bi-map','Open planning'],
   ['Reports Hub','Open existing subscriber, finance, network and operations reports.','ipilot.reports','bi-bar-chart-line','Browse reports'],
   ['BTRC Report Draft','Filter customer connection records and export a reviewable CSV draft.','ipilot.btrc','bi-file-earmark-spreadsheet','Create draft'],
   ['iPilot Terminal','Read-only AI diagnostics for routers, PPPoE, ONUs, optical and billing records.','ipilot.terminal','bi-terminal','Open terminal'],
  ])
  @foreach($sections as [$title,$description,$route,$icon,$action])
  <div class="col-12 col-md-6 col-xl-3"><div class="card h-100 shadow-sm"><div class="card-body d-flex flex-column"><div class="d-flex align-items-center gap-2 mb-3"><span class="rounded-circle bg-primary-subtle text-primary p-2"><i class="bi {{ $icon }} fs-5"></i></span><h5 class="mb-0">{{ $title }}</h5></div><p class="text-muted small flex-grow-1">{{ $description }}</p><a href="{{ route($route) }}" class="btn btn-outline-primary btn-sm align-self-start">{{ $action }} <i class="bi bi-arrow-right ms-1"></i></a></div></div></div>
  @endforeach
 </div>
 <div class="alert alert-info mt-4 mb-0"><strong>Implementation note:</strong> iPilot reference pages are being connected to X-Link’s Laravel data and permission model. No third-party API keys from the archive were imported. Map positions are shown only when coordinates exist; integrations remain inactive until configured and tested.</div>
</div>
</x-app-layout>
