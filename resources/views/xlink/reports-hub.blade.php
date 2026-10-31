<x-app-layout>
<div class="container-fluid py-3">
 <div class="mb-4"><h2 class="mb-1">Reports Hub</h2><p class="text-muted mb-0">Central access to existing X-Link reports. Each link opens its current live report screen.</p></div>
 @php($groups=[
  ['Subscribers & Service',[['Customer List','customers.index','bi-people'],['Due Customers','broadband-due-customers','bi-calendar-x'],['Paid Customers','broadband-paid-customers','bi-check-circle'],['Inactive Customers','broadband-inactive-customers','bi-person-dash'],['New Customers','broadband-new-customers','bi-person-plus'],['Customer Summary','customer-summary','bi-card-list']]],
  ['Revenue & Collection',[['Billing & Finance Dashboard','billing-finance','bi-cash-stack'],['Payment Collection','payment-collection','bi-wallet2'],['Collection Report','collection-report.index','bi-receipt'],['SMS Delivery Reports','sms-service.reports','bi-chat-square-text']]],
  ['Network & Operations',[['Network Inventory','network-inventory','bi-hdd-network'],['MikroTik Management','network-inventory.mikrotik','bi-router'],['OLT Management','network-inventory.olt','bi-diagram-3'],['ONU Management','network-inventory.onu-management','bi-hdd-network'],['Customer Network Monitoring','network-monitoring','bi-activity'],['Network Events & Alerts','network-events','bi-bell']]],
  ['Special Exports',[['BTRC Report Draft','ipilot.btrc','bi-file-earmark-spreadsheet'],['Stock Inventory Reconciliation','stock-inventory.reports','bi-box-seam'],['Detailed Disconnection Report','dis-report','bi-file-earmark-text']]]
 ])
 @foreach($groups as [$heading,$items])
 <section class="mb-4"><h5 class="mb-3">{{ $heading }}</h5><div class="row g-3">
  @foreach($items as [$label,$route,$icon])
  <div class="col-12 col-sm-6 col-lg-4 col-xxl-3"><a href="{{ route($route) }}" class="card h-100 text-decoration-none"><div class="card-body d-flex align-items-center gap-3"><span class="fs-4 text-primary"><i class="bi {{ $icon }}"></i></span><span class="fw-semibold text-body">{{ $label }}</span><i class="bi bi-arrow-up-right ms-auto text-muted"></i></div></a></div>
  @endforeach
 </div></section>
 @endforeach
 <div class="alert alert-warning"><strong>Report readiness:</strong> customer, finance, network and stock reports use existing live X-Link tables. Several advanced iPilot reports (operator performance, gateway revenue, distributor card stock) are not exposed here until their dedicated source tables and filters are verified. BTRC export is a draft, not a regulator-approved template.</div>
</div>
</x-app-layout>
