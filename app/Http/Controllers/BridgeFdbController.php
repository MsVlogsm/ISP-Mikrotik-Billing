<?php

namespace App\Http\Controllers;

use App\Models\NetworkBridgeFdbEntry;
use App\Models\NetworkBridgeFdbSnapshot;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Services\Olt\BridgeFdbSnmpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class BridgeFdbController extends Controller
{
    public function index(Request $request, NetworkInventoryDevice $device): View
    {
        abort_unless(in_array($device->type, ['olt', 'switch'], true), 404);

        $snapshot = NetworkBridgeFdbSnapshot::query()
            ->where('network_inventory_device_id', $device->id)
            ->latest('sampled_at')
            ->first();

        $search = trim((string) $request->query('q', ''));
        $vlan = trim((string) $request->query('vlan', ''));
        $port = trim((string) $request->query('port', ''));
        $status = trim((string) $request->query('status', ''));

        $entriesQuery = $snapshot
            ? NetworkBridgeFdbEntry::query()->where('snapshot_id', $snapshot->id)
            : NetworkBridgeFdbEntry::query()->whereRaw('1 = 0');

        if ($search !== '') {
            $entriesQuery->where(function ($query) use ($search): void {
                $query->where('mac_address', 'like', '%'.$search.'%')
                    ->orWhere('interface_name', 'like', '%'.$search.'%')
                    ->orWhere('if_index', 'like', '%'.$search.'%')
                    ->orWhere('bridge_port', 'like', '%'.$search.'%');
            });
        }
        if ($vlan !== '' && ctype_digit($vlan)) {
            $entriesQuery->where('vlan_id', (int) $vlan);
        }
        if ($port !== '') {
            $entriesQuery->where(function ($query) use ($port): void {
                $query->where('interface_name', 'like', '%'.$port.'%')
                    ->orWhere('if_index', $port)
                    ->orWhere('bridge_port', $port);
            });
        }
        if (in_array($status, ['learned', 'self', 'management', 'other', 'invalid'], true)) {
            $entriesQuery->where('entry_status', $status);
        }

        $entries = $entriesQuery->orderBy('vlan_id')->orderBy('interface_name')->orderBy('mac_address')
            ->paginate(100)->withQueryString();

        $this->attachExactSubscriberContext($entries->getCollection(), $device);

        $stats = [
            'total' => (int) ($snapshot?->entry_count ?? 0),
            'learned' => (int) ($snapshot?->learned_count ?? 0),
            'other' => (int) ($snapshot?->other_count ?? 0),
            'self' => (int) ($snapshot?->self_count ?? 0),
            'management' => (int) ($snapshot?->management_count ?? 0),
            'invalid' => (int) ($snapshot?->invalid_count ?? 0),
            'vlans' => $snapshot
                ? NetworkBridgeFdbEntry::query()->where('snapshot_id', $snapshot->id)->whereNotNull('vlan_id')->distinct()->count('vlan_id')
                : 0,
            'interfaces' => $snapshot
                ? NetworkBridgeFdbEntry::query()->where('snapshot_id', $snapshot->id)->whereNotNull('if_index')->distinct()->count('if_index')
                : 0,
        ];

        return view('xlink.bridge-fdb', [
            'device' => $device,
            'snapshot' => $snapshot,
            'entries' => $entries,
            'stats' => $stats,
            'search' => $search,
            'vlan' => $vlan,
            'port' => $port,
            'status' => $status,
            'pollEnabled' => (bool) config('bridge_fdb.live_poll_enabled', false),
        ]);
    }

    public function poll(Request $request, NetworkInventoryDevice $device, BridgeFdbSnmpService $service): RedirectResponse
    {
        abort_unless(in_array($device->type, ['olt', 'switch'], true), 404);

        if (! (bool) config('bridge_fdb.live_poll_enabled', false)) {
            return redirect()->route('network-inventory.device.bridge-fdb', $device)
                ->with('bridge_fdb_success', false)
                ->with('bridge_fdb_message', 'Live Bridge FDB polling is disabled. Configure a dedicated read-only SNMP community and enable OLT_BRIDGE_FDB_POLL_ENABLED after staging verification.');
        }

        $request->validate([
            'confirm_read_only_poll' => ['required', 'accepted'],
        ]);

        try {
            $snapshot = $service->poll($device);
            $success = in_array($snapshot->status, ['ok', 'empty'], true);
            $message = $snapshot->error_message
                ?: sprintf(
                    '%s FDB read completed: %d entries (%d learned), %s source, %d ms.',
                    strtoupper((string) $snapshot->source),
                    (int) $snapshot->entry_count,
                    (int) $snapshot->learned_count,
                    strtoupper((string) $snapshot->status),
                    (int) $snapshot->poll_duration_ms
                );

            return redirect()->route('network-inventory.device.bridge-fdb', $device)
                ->with('bridge_fdb_success', $success)
                ->with('bridge_fdb_message', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('network-inventory.device.bridge-fdb', $device)
                ->with('bridge_fdb_success', false)
                ->with('bridge_fdb_message', 'Bridge FDB poll failed: '.$exception->getMessage());
        }
    }

    private function attachExactSubscriberContext(Collection $entries, NetworkInventoryDevice $device): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $macs = $entries->pluck('mac_address')->filter()->unique()->values();
        $linkQuery = OltOnuCustomerPppLink::query()
            ->with(['customer', 'pppUser', 'onuMapping'])
            ->where('status', 'active')
            ->whereNotNull('client_mac')
            ->whereIn('client_mac', $macs);
        if ($device->type === 'olt') {
            $linkQuery->whereHas('onuMapping', fn ($query) => $query->where('olt_device_id', $device->id));
        }
        $links = $linkQuery->get()
            ->groupBy(fn (OltOnuCustomerPppLink $link) => $this->normalizeMac($link->client_mac));

        $onuMacMappings = OltOnuCustomerMapping::query()
            ->with(['customer', 'pppUser', 'subscriberLinks.customer', 'subscriberLinks.pppUser'])
            ->where('olt_device_id', $device->id)
            ->whereNotNull('onu_mac')
            ->whereIn('onu_mac', $macs)
            ->get()
            ->groupBy(fn (OltOnuCustomerMapping $mapping) => $this->normalizeMac($mapping->onu_mac));

        $onuSlots = [];
        foreach ($entries as $entry) {
            if (preg_match('/^EPON\d+\/(\d+):(\d+)$/i', trim((string) $entry->interface_name), $matches)) {
                $onuSlots[$this->normalizePon($matches[1]).'|'.trim($matches[2])] = true;
            }
        }
        $slotMappings = collect();
        if ($onuSlots) {
            $onuIds = array_values(array_unique(array_map(static fn ($key) => explode('|', $key, 2)[1], array_keys($onuSlots))));
            $slotMappings = OltOnuCustomerMapping::query()
                ->with(['customer', 'pppUser', 'subscriberLinks.customer', 'subscriberLinks.pppUser'])
                ->where('olt_device_id', $device->id)
                ->whereIn('onu_id', $onuIds)
                ->get()
                ->groupBy(fn (OltOnuCustomerMapping $mapping) => $this->normalizePon($mapping->pon_port).'|'.trim((string) $mapping->onu_id));
        }

        foreach ($entries as $entry) {
            $mac = $this->normalizeMac($entry->mac_address);
            $linkMatches = $links->get($mac, collect())->unique(fn ($link) => $link->id)->values();

            if ($linkMatches->count() === 1) {
                $link = $linkMatches->first();
                $entry->setAttribute('subscriber_name', $link->customer?->customer_name ?: $link->customer?->customer_unique_id);
                $entry->setAttribute('subscriber_id', $link->customer?->customer_unique_id);
                $entry->setAttribute('pppoe_username', $link->pppUser?->username);
                $entry->setAttribute('identity_source', 'Exact client MAC link');

                continue;
            }

            if ($linkMatches->count() > 1) {
                $entry->setAttribute('identity_source', 'Ambiguous client MAC link');

                continue;
            }

            $onuMatches = $onuMacMappings->get($mac, collect())->unique('id')->values();
            if ($onuMatches->count() === 1) {
                $mapping = $onuMatches->first();
                $entry->setAttribute('onu_identity', 'PON '.$mapping->pon_port.' / ONU '.$mapping->onu_id);
                $activeLinks = $mapping->subscriberLinks->where('status', 'active')
                    ->unique(fn ($link) => $link->customer_id.'|'.$link->ppp_user_id)->values();
                if ($activeLinks->count() === 1) {
                    $link = $activeLinks->first();
                    $entry->setAttribute('subscriber_name', $link->customer?->customer_name ?: $link->customer?->customer_unique_id);
                    $entry->setAttribute('subscriber_id', $link->customer?->customer_unique_id);
                    $entry->setAttribute('pppoe_username', $link->pppUser?->username);
                } elseif ($activeLinks->count() > 1) {
                    $entry->setAttribute('identity_source', 'ONU has multiple active subscriber links');

                    continue;
                } else {
                    $entry->setAttribute('subscriber_name', $mapping->customer?->customer_name ?: $mapping->customer?->customer_unique_id);
                    $entry->setAttribute('subscriber_id', $mapping->customer?->customer_unique_id);
                    $entry->setAttribute('pppoe_username', $mapping->pppUser?->username);
                }
                $entry->setAttribute('identity_source', 'Exact ONU MAC identity');

                continue;
            } elseif ($onuMatches->count() > 1) {
                $entry->setAttribute('identity_source', 'Ambiguous ONU MAC identity');

                continue;
            }

            if (preg_match('/^EPON\d+\/(\d+):(\d+)$/i', trim((string) $entry->interface_name), $matches)) {
                $slotKey = $this->normalizePon($matches[1]).'|'.trim($matches[2]);
                $slotMatches = $slotMappings->get($slotKey, collect())->unique('id')->values();
                if ($slotMatches->count() === 1) {
                    $mapping = $slotMatches->first();
                    $entry->setAttribute('onu_identity', 'PON '.$mapping->pon_port.' / ONU '.$mapping->onu_id);
                    $activeLinks = $mapping->subscriberLinks->where('status', 'active')->unique(fn ($link) => $link->customer_id.'|'.$link->ppp_user_id)->values();
                    if ($activeLinks->count() === 1) {
                        $link = $activeLinks->first();
                        $entry->setAttribute('subscriber_name', $link->customer?->customer_name ?: $link->customer?->customer_unique_id);
                        $entry->setAttribute('subscriber_id', $link->customer?->customer_unique_id);
                        $entry->setAttribute('pppoe_username', $link->pppUser?->username);
                    } elseif ($activeLinks->count() > 1) {
                        $entry->setAttribute('identity_source', 'ONU has multiple active subscriber links');

                        continue;
                    } else {
                        $entry->setAttribute('subscriber_name', $mapping->customer?->customer_name ?: $mapping->customer?->customer_unique_id);
                        $entry->setAttribute('subscriber_id', $mapping->customer?->customer_unique_id);
                        $entry->setAttribute('pppoe_username', $mapping->pppUser?->username);
                    }
                    $entry->setAttribute('identity_source', 'Exact PON/ONU interface identity');
                } elseif ($slotMatches->count() > 1) {
                    $entry->setAttribute('identity_source', 'Ambiguous PON/ONU interface mapping');
                }
            }
        }
    }

    private function normalizePon(?string $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/(?:EPON)?\d*\/(\d+)$/i', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    private function normalizeMac(?string $value): string
    {
        return strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $value) ?? '');
    }
}
