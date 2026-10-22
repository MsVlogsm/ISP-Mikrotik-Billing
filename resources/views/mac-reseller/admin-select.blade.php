<x-app-layout>
    <x-slot name="header">{{ __('All MAC Reseller Portals') }}</x-slot>
    <div class="container-fluid px-3 px-lg-4 py-4">
        @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
        @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div><h4 class="mb-1">{{ __('Select a MAC Reseller Portal') }}</h4><p class="text-muted mb-0">{{ __('Super Admin can open any MAC Reseller portal. Customer records remain scoped to the selected reseller.') }}</p></div>
            @if ($selectedResellerId) <form method="POST" action="{{ route('mac-reseller.exit') }}">@csrf<button class="btn btn-outline-secondary" type="submit">{{ __('Exit Portal') }}</button></form> @endif
        </div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>{{ __('Reseller') }}</th><th>{{ __('Email') }}</th><th>{{ __('Clients') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Portal') }}</th></tr></thead>
            <tbody>
            @forelse ($resellers as $reseller)
                <tr><td><strong>{{ $reseller->company ?: ($reseller->user?->name ?: __('Reseller').' #'.$reseller->id) }}</strong><div class="small text-muted">ID: {{ $reseller->id }}</div></td><td>{{ $reseller->user?->email ?: '—' }}</td><td>{{ $reseller->customers_count }}</td><td><span class="badge {{ $reseller->isActive() ? 'bg-success' : 'bg-secondary' }}">{{ ucfirst($reseller->status ?: 'unknown') }}</span></td><td class="text-end"><form method="POST" action="{{ route('mac-reseller.select') }}">@csrf<input type="hidden" name="reseller_id" value="{{ $reseller->id }}"><button class="btn btn-sm {{ (int)$selectedResellerId === (int)$reseller->id ? 'btn-primary' : 'btn-outline-primary' }}" type="submit">{{ (int)$selectedResellerId === (int)$reseller->id ? __('Open Again') : __('Access Portal') }}</button></form></td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">{{ __('No MAC Reseller accounts found.') }}</td></tr>
            @endforelse
            </tbody></table></div></div>
    </div>
</x-app-layout>
