<?php

namespace App\Http\Controllers;

use App\Models\NetworkInventoryDevice;
use App\Models\OnuMacLedger;
use Illuminate\Http\Request;

class OnuMacLedgerController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('Super Admin'), 403);

        $query = OnuMacLedger::with(['olt', 'customer'])->orderByDesc('last_seen_at')->orderByDesc('id');
        $q = trim((string) $request->query('q', ''));
        $oltId = trim((string) $request->query('olt', ''));
        $status = trim((string) $request->query('status', ''));
        $changed = $request->query('changed') === '1';

        if ($q !== '') {
            $query->where(function ($where) use ($q): void {
                $where->where('current_mac', 'like', "%{$q}%")
                    ->orWhere('previous_mac', 'like', "%{$q}%")
                    ->orWhere('serial_no', 'like', "%{$q}%")
                    ->orWhere('onu_id', 'like', "%{$q}%")
                    ->orWhere('pon_port', 'like', "%{$q}%")
                    ->orWhere('customer_unique_id', 'like', "%{$q}%")
                    ->orWhere('pppoe_username', 'like', "%{$q}%")
                    ->orWhereHas('olt', fn ($olt) => $olt->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('customer_name', 'like', "%{$q}%"));
            });
        }

        if ($oltId !== '' && ctype_digit($oltId)) {
            $query->where('olt_device_id', (int) $oltId);
        }
        if (in_array($status, ['online', 'offline', 'unknown'], true)) {
            $query->where('status', $status);
        }
        if ($changed) {
            $query->whereNotNull('mac_changed_at');
        }

        $mappings = $query->paginate(50)->withQueryString();
        $base = OnuMacLedger::query();
        $stats = [
            'total' => (clone $base)->count(),
            'mapped' => (clone $base)->whereNotNull('customer_id')->count(),
            'unmapped' => (clone $base)->whereNull('customer_id')->count(),
            'mac_changes' => (clone $base)->whereNotNull('mac_changed_at')->count(),
            'stale' => (clone $base)->where('status', 'online')->where(function ($stale): void {
                $stale->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(5));
            })->count(),
        ];
        $olts = NetworkInventoryDevice::type('olt')->orderBy('name')->get(['id', 'name', 'location']);

        return view('xlink.onu-mac-ledger', compact('mappings', 'stats', 'olts', 'q', 'oltId', 'status', 'changed'));
    }
}
