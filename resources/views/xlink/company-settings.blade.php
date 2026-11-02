<x-app-layout>
<div class="container-fluid px-1"><div class="mb-4"><div class="text-uppercase small text-muted fw-semibold">Administration</div><h3 class="mb-1">Company Settings</h3><p class="text-muted">Company identity, service configuration, billing operations, messaging and payment setup.</p></div>
<div class="row g-3">
@foreach([
['Area & Zone Setup','Manage service zones, sub-zones, TJ distribution boxes, districts and upazilas.','company-settings.area-zone-setup','bi-geo-alt'],
['Address Field Configuration','Manage customer address field types, required settings, form order and receipt order. Area/Zone values are managed centrally.','address-setup','bi-signpost-split'],
['Service Configuration','Manage connection types, network protocols, customer types, billing statuses and bandwidth types.','company-settings.service-configuration','bi-sliders'],
['Billing & Operation','Configure the active disconnect checks, expired profile, invoice prefix and currency.','company-settings.billing-operations','bi-receipt'],
['Due Customer Auto Call','Schedule voice-call reminders for customers with outstanding bills and review call history.','due-call-reminders.index','bi-telephone-outbound'],
['Company Setup','Company identity, branding and invoice/document settings.','site-settings','bi-building'],
['Email Settings','SMTP credentials, sender identity, reply-to and a test email.','company-settings.email','bi-envelope-at'],
['Payment Gateway','Existing mobile banking and payment gateway configuration.','mobile-banking.settings','bi-credit-card'],
['Application Settings','Roles, user management, audit logs and system configuration.','xlink.system-settings','bi-gear']
] as [$title,$description,$route,$icon])
<div class="col-sm-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex flex-column"><div class="d-flex align-items-center gap-2 mb-3"><i class="bi {{ $icon }} fs-4 text-primary"></i><h5 class="mb-0">{{ $title }}</h5></div><p class="text-muted small flex-grow-1">{{ $description }}</p><a class="btn btn-outline-primary btn-sm align-self-start" href="{{ route($route) }}">Open Settings</a></div></div></div>
@endforeach
</div></div>
</x-app-layout>
