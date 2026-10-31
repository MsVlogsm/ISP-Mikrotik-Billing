<?php

namespace App\Livewire;

use App\Models\NetworkInventoryDevice;
use App\Models\NetworkOpticalAudit as Audit;
use App\Models\OltOnuCustomerMapping;
use App\Models\RouterList;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyLink;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalAudit extends Component
{
    use WithPagination;

    public string $search = '';
    public string $oltFilter = '';
    public string $auditName = '';
    public string $auditNotes = '';
    public string $message = '';
    public ?int $selectedAuditId = null;
    public ?array $virtualTestResult = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedOltFilter(): void { $this->resetPage(); }


    public function runVirtualTest(string $test): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) abort(403);
        $allowed = ['mikrotik', 'olt', 'splitter', 'user', 'fiber', 'ping', 'bandwidth', 'netflow', 'internet'];
        abort_unless(in_array($test, $allowed, true), 404);

        $result = ['test' => $test, 'checked_at' => now()->format('d M Y, h:i:s A'), 'status' => 'limited', 'summary' => '', 'details' => []];
        switch ($test) {
            case 'mikrotik':
                $total = RouterList::count();
                $connected = RouterList::where('action', 'connected')->count();
                $result['status'] = $total && $connected === $total ? 'success' : ($connected ? 'warning' : 'failed');
                $result['summary'] = "{$connected} of {$total} MikroTik routers are marked connected in the saved router status.";
                $result['details'] = ['Connected' => $connected, 'Not connected' => max(0, $total - $connected)];
                break;
            case 'olt':
                $total = NetworkInventoryDevice::where('type', 'olt')->count();
                $online = NetworkInventoryDevice::where('type', 'olt')->whereIn('health_status', ['online', 'ready', 'connected'])->count();
                $result['status'] = $online ? 'success' : 'warning';
                $result['summary'] = "{$total} OLT inventory records; {$online} currently report a healthy stored status. This is not a new hardware probe.";
                $result['details'] = ['OLT records' => $total, 'Healthy status' => $online, 'ONU mappings' => OltOnuCustomerMapping::count()];
                break;
            case 'splitter':
                $splitters = NetworkTopologyNode::where('type', 'splitter')->count();
                $links = NetworkTopologyLink::where('connection_type', 'splitter')->count();
                $result['status'] = $splitters ? 'success' : 'warning';
                $result['summary'] = $splitters ? "{$splitters} splitter records and {$links} splitter connections are configured." : 'No splitter assets are configured yet; add splitter nodes in Topology Designer.';
                $result['details'] = ['Splitter assets' => $splitters, 'Splitter links' => $links];
                break;
            case 'user':
                $mapped = OltOnuCustomerMapping::whereNotNull('customer_id')->count();
                $total = OltOnuCustomerMapping::count();
                $result['status'] = $mapped ? 'success' : 'warning';
                $result['summary'] = "{$mapped} of {$total} ONU records are mapped to customer records.";
                $result['details'] = ['Mapped ONU' => $mapped, 'Unmapped ONU' => max(0, $total - $mapped)];
                break;
            case 'fiber':
                $total = OltOnuCustomerMapping::count();
                $rx = OltOnuCustomerMapping::whereNotNull('rx_power')->count();
                $tx = OltOnuCustomerMapping::whereNotNull('tx_power')->count();
                $result['status'] = $rx ? 'success' : 'warning';
                $result['summary'] = "Stored optical power readings: RX {$rx}/{$total}, TX {$tx}/{$total}. Values are displayed as reported by the OLT; no physical OTDR trace or distance is inferred.";
                $result['details'] = ['RX readings' => $rx, 'TX readings' => $tx, 'Missing RX' => max(0, $total - $rx)];
                break;
            case 'ping':
                $result['status'] = 'unavailable';
                $result['summary'] = 'Live customer ping is unavailable until a router-side ping adapter is configured. No ping result is fabricated.';
                break;
            case 'bandwidth':
                $result['status'] = 'limited';
                $result['summary'] = 'Bandwidth test is limited to stored monitoring data. A live throughput test requires an approved MikroTik bandwidth-test endpoint and target.';
                $result['details'] = ['Router records' => RouterList::count()];
                break;
            case 'netflow':
                $result['status'] = 'unavailable';
                $result['summary'] = 'NetFlow/sFlow collector is not configured for this test. Configure a collector before reporting traffic-flow results.';
                break;
            case 'internet':
                $result['status'] = 'unavailable';
                $result['summary'] = 'No approved external connectivity probe is configured. Internet reachability is not inferred from router status.';
                break;
        }
        $this->virtualTestResult = $result;
    }

    public function saveSnapshot(): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) abort(403);

        $this->validate([
            'auditName' => ['required', 'string', 'max:140'],
            'auditNotes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($this->oltFilter !== '') {
            $this->validate([
                'oltFilter' => ['integer', Rule::exists('network_inventory_devices', 'id')->where('type', 'olt')],
            ]);
        }

        $rows = $this->filteredReadings()->limit(1000)->get();
        $readings = $rows->map(fn ($m) => [
            'olt_id' => $m->olt_device_id,
            'olt_name' => $m->olt?->name ?: $m->olt?->hostname ?: 'OLT #'.$m->olt_device_id,
            'customer_id' => $m->customer_id,
            'customer_name' => $m->customer?->customer_name ?: $m->customer?->customer_unique_id ?: 'Unmapped',
            'pon_port' => $m->pon_port,
            'onu_id' => $m->onu_id,
            'onu_serial' => $m->onu_serial,
            'onu_mac' => $m->onu_mac,
            'onu_type' => $m->onu_type,
            'status' => $m->status,
            'rx_power' => $m->rx_power,
            'tx_power' => $m->tx_power,
            'onu_ip' => $m->onu_ip,
            'last_seen_at' => $m->last_seen_at?->toISOString(),
        ])->values()->all();

        if (! count($readings)) {
            $this->addError('auditName', 'There are no ONU mapping records to snapshot for these filters.');
            return;
        }

        $oltId = $this->oltFilter !== '' ? (int) $this->oltFilter : null;
        Audit::create([
            'name' => trim($this->auditName),
            'olt_device_id' => $oltId,
            'reading_count' => count($readings),
            'readings' => $readings,
            'notes' => $this->auditNotes ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['auditName', 'auditNotes']);
        $this->message = 'Optical health snapshot saved with '.count($readings).' ONU records. This is a snapshot of stored OLT readings, not a physical OTDR trace.';
    }

    public function viewAudit(int $id): void
    {
        $this->selectedAuditId = Audit::whereKey($id)->exists() ? $id : abort(404);
    }

    public function clearAudit(): void
    {
        $this->selectedAuditId = null;
    }

    private function filteredReadings()
    {
        $query = OltOnuCustomerMapping::query()->with(['olt', 'customer', 'pppUser']);
        if ($this->oltFilter !== '') $query->where('olt_device_id', (int) $this->oltFilter);
        $search = trim($this->search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('onu_id', 'like', $like)
                    ->orWhere('onu_serial', 'like', $like)
                    ->orWhere('onu_mac', 'like', $like)
                    ->orWhere('pon_port', 'like', $like)
                    ->orWhere('onu_ip', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', $like)->orWhere('customer_unique_id', 'like', $like)->orWhere('mobile', 'like', $like))
                    ->orWhereHas('pppUser', fn ($p) => $p->where('username', 'like', $like))
                    ->orWhereHas('olt', fn ($o) => $o->where('name', 'like', $like)->orWhere('ip_address', 'like', $like));
            });
        }
        return $query->latest('last_seen_at')->latest('id');
    }

    public function render()
    {
        $query = $this->filteredReadings();
        $total = (clone $query)->count();
        $withRx = (clone $query)->whereNotNull('rx_power')->count();
        $online = (clone $query)->where('status', 'online')->count();
        $stale = (clone $query)->where(function ($q) {
            $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(24));
        })->count();

        return view('livewire.optical-audit', [
            'readings' => $query->paginate(25),
            'olts' => NetworkInventoryDevice::where('type', 'olt')->orderBy('name')->get(),
            'audits' => Audit::with(['olt', 'createdBy'])->latest()->limit(12)->get(),
            'selectedAudit' => $this->selectedAuditId ? Audit::with(['olt', 'createdBy'])->find($this->selectedAuditId) : null,
            'total' => $total, 'withRx' => $withRx, 'online' => $online, 'stale' => $stale,
        ])->layout('layouts.app');
    }
}
