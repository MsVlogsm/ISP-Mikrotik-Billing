<?php

namespace App\Livewire;

use App\Models\NetworkInventoryDevice;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyMap;
use App\Models\OltOnuCustomerMapping;
use App\Models\RouterList;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;

class NetworkTopology extends Component
{
    use WithFileUploads;
    public string $mode = 'designer';
    public int $mapId = 0;
    public string $newMapName = '';
    public string $newMapDescription = '';
    public $topologyImportFile;
    public string $source_key = '';
    public string $target_key = '';
    public string $connection_type = 'fiber_core';
    public string $label = '';
    public string $capacity_mbps = '';
    public string $traffic_mbps = '';
    public string $latency_ms = '';
    public string $packet_loss = '';
    public string $fiber_core = '';
    public string $fiber_type = 'singlemode';
    public string $splitter_ratio = '';
    public string $port_reference = '';
    public string $source_port = '';
    public string $target_port = '';
    public string $input_ports = '1';
    public string $output_ports = '';
    public string $message = '';
    public string $newNodeType = 'splitter';
    public string $newNodeName = '';
    public string $newNodeLocation = '';
    public string $newNodeNotes = '';
    public string $newNodeLatitude = '';
    public string $newNodeLongitude = '';

    public function mount(string $mode = 'designer'): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) {
            abort(403);
        }
        abort_unless(in_array($mode, ['designer', 'live'], true), 404);
        $this->mode = $mode;
        $default = NetworkTopologyMap::where('is_default', true)->first() ?: NetworkTopologyMap::orderBy('id')->first();
        abort_unless($default, 500);
        $this->mapId = (int) $default->id;
    }

    public function updatedMapId(): void
    {
        $this->reset(['source_key', 'target_key', 'source_port', 'target_port']);
        $this->message = 'Topology map switched.';
    }

    public function createMap(): void
    {
        if ($this->mode !== 'designer') abort(403);
        $data = $this->validate(['newMapName' => ['required','string','max:120','unique:network_topology_maps,name'], 'newMapDescription' => ['nullable','string','max:1000']]);
        $map = NetworkTopologyMap::create(['name'=>trim($data['newMapName']), 'slug'=>\Illuminate\Support\Str::slug($data['newMapName']).'-'.uniqid(), 'description'=>$data['newMapDescription'] ?: null, 'is_default'=>false, 'created_by'=>auth()->id()]);
        $this->mapId = (int) $map->id;
        $this->reset(['newMapName','newMapDescription','source_key','target_key','source_port','target_port']);
        $this->message = 'New topology map created and selected.';
    }

    public function deleteMap(): void
    {
        if ($this->mode !== 'designer') abort(403);
        $map = NetworkTopologyMap::findOrFail($this->mapId);
        if ($map->is_default) { $this->message = 'The Main Network map cannot be deleted.'; return; }
        $default = NetworkTopologyMap::where('is_default', true)->firstOrFail();
        $map->delete();
        $this->mapId = (int) $default->id;
        $this->message = 'Topology map deleted.';
    }

    public function cloneMap(): void
    {
        if ($this->mode !== 'designer') abort(403);

        $this->validate([
            'newMapName' => ['required', 'string', 'max:120', 'unique:network_topology_maps,name'],
            'newMapDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $source = NetworkTopologyMap::findOrFail($this->mapId);
        $name = trim($this->newMapName);
        $description = trim((string) $this->newMapDescription);

        DB::transaction(function () use ($source, $name, $description): void {
            $clone = NetworkTopologyMap::create([
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
                'description' => $description !== '' ? $description : $source->description,
                'is_default' => false,
                'created_by' => auth()->id(),
            ]);

            $keyMap = [];
            $nodes = NetworkTopologyNode::where('map_id', $source->id)->get();
            foreach ($nodes as $node) {
                $created = NetworkTopologyNode::create([
                    'map_id' => $clone->id,
                    'type' => $node->type,
                    'subtype' => $node->subtype,
                    'name' => $node->name,
                    'location' => $node->location,
                    'port_reference' => $node->port_reference,
                    'splitter_ratio' => $node->splitter_ratio,
                    'input_ports' => $node->input_ports,
                    'output_ports' => $node->output_ports,
                    'port_capacity' => $node->port_capacity,
                    'latitude' => $node->latitude,
                    'longitude' => $node->longitude,
                    'notes' => $node->notes,
                    'is_published' => $node->is_published,
                    'created_by' => auth()->id(),
                ]);
                $keyMap['custom:' . $node->id] = 'topology:' . $created->id;
                $keyMap['topology:' . $node->id] = 'topology:' . $created->id;
            }

            $links = NetworkTopologyLink::where('map_id', $source->id)->get();
            foreach ($links as $link) {
                NetworkTopologyLink::create([
                    'map_id' => $clone->id,
                    'source_key' => $keyMap[$link->source_key] ?? $link->source_key,
                    'target_key' => $keyMap[$link->target_key] ?? $link->target_key,
                    'connection_type' => $link->connection_type,
                    'label' => $link->label,
                    'capacity_mbps' => $link->capacity_mbps,
                    'traffic_mbps' => $link->traffic_mbps,
                    'latency_ms' => $link->latency_ms,
                    'packet_loss' => $link->packet_loss,
                    'fiber_core' => $link->fiber_core,
                    'fiber_type' => $link->fiber_type,
                    'source_port' => $link->source_port,
                    'target_port' => $link->target_port,
                    'status' => $link->status,
                    'is_published' => $link->is_published,
                    'created_by' => auth()->id(),
                ]);
            }

            $this->mapId = (int) $clone->id;
        });

        $this->reset(['newMapName', 'newMapDescription', 'source_key', 'target_key', 'source_port', 'target_port']);
        $this->message = 'Topology map cloned successfully. The cloned map is now selected.';
    }


    public function exportMap()
    {
        if ($this->mode !== 'designer') abort(403);
        $map = NetworkTopologyMap::findOrFail($this->mapId);
        $nodes = NetworkTopologyNode::where('map_id', $this->mapId)->get();
        $links = NetworkTopologyLink::where('map_id', $this->mapId)->get();
        $payload = [
            'format' => 'xlink-topology-backup', 'version' => 1, 'exported_at' => now()->toIso8601String(),
            'map' => ['name'=>$map->name, 'slug'=>$map->slug, 'description'=>$map->description, 'is_default'=>$map->is_default],
            'nodes' => $nodes->map(fn($n)=>['backup_key'=>'custom:'.$n->id,'type'=>$n->type,'subtype'=>$n->subtype,'name'=>$n->name,'location'=>$n->location,'port_reference'=>$n->port_reference,'splitter_ratio'=>$n->splitter_ratio,'input_ports'=>$n->input_ports,'output_ports'=>$n->output_ports,'port_capacity'=>$n->port_capacity,'latitude'=>$n->latitude,'longitude'=>$n->longitude,'notes'=>$n->notes,'is_published'=>$n->is_published])->values()->all(),
            'links' => $links->map(fn($l)=>['source_key'=>$l->source_key,'target_key'=>$l->target_key,'connection_type'=>$l->connection_type,'label'=>$l->label,'capacity_mbps'=>$l->capacity_mbps,'traffic_mbps'=>$l->traffic_mbps,'latency_ms'=>$l->latency_ms,'packet_loss'=>$l->packet_loss,'fiber_core'=>$l->fiber_core,'fiber_type'=>$l->fiber_type,'source_port'=>$l->source_port,'target_port'=>$l->target_port,'status'=>$l->status,'is_published'=>$l->is_published])->values()->all(),
        ];
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $map->name).'-'.now()->format('Ymd-His').'.json';
        return response()->streamDownload(function() use ($payload) { echo json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); }, $name, ['Content-Type'=>'application/json']);
    }

    public function importMap(): void
    {
        if ($this->mode !== 'designer') abort(403);
        $this->validate(['topologyImportFile'=>['required','file','max:5120','mimetypes:application/json,text/plain']]);
        $raw = file_get_contents($this->topologyImportFile->getRealPath());
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['format'] ?? '') !== 'xlink-topology-backup' || (int)($payload['version'] ?? 0) !== 1) { $this->addError('topologyImportFile','Invalid X-Link topology backup file.'); return; }
        $map = NetworkTopologyMap::findOrFail($this->mapId);
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
        DB::transaction(function() use ($map,$nodes,$links) {
            NetworkTopologyLink::where('map_id',$map->id)->delete();
            NetworkTopologyNode::where('map_id',$map->id)->delete();
            $keyMap = [];
            foreach ($nodes as $n) {
                if (!is_array($n) || empty($n['name']) || empty($n['type'])) continue;
                $created = NetworkTopologyNode::create(['map_id'=>$map->id,'type'=>$n['type'],'subtype'=>$n['subtype']??null,'name'=>$n['name'],'location'=>$n['location']??null,'port_reference'=>$n['port_reference']??null,'splitter_ratio'=>$n['splitter_ratio']??null,'input_ports'=>$n['input_ports']??null,'output_ports'=>$n['output_ports']??null,'port_capacity'=>$n['port_capacity']??null,'latitude'=>$n['latitude']??null,'longitude'=>$n['longitude']??null,'notes'=>$n['notes']??null,'is_published'=>(bool)($n['is_published']??false),'created_by'=>auth()->id()]);
                if (!empty($n['backup_key'])) $keyMap[$n['backup_key']] = 'topology:'.$created->id;
            }
            foreach ($links as $l) {
                if (!is_array($l) || empty($l['source_key']) || empty($l['target_key']) || empty($l['connection_type'])) continue;
                $source = $keyMap[$l['source_key']] ?? $l['source_key']; $target = $keyMap[$l['target_key']] ?? $l['target_key'];
                NetworkTopologyLink::create(['map_id'=>$map->id,'source_key'=>$source,'target_key'=>$target,'connection_type'=>$l['connection_type'],'label'=>$l['label']??null,'capacity_mbps'=>$l['capacity_mbps']??null,'traffic_mbps'=>$l['traffic_mbps']??null,'latency_ms'=>$l['latency_ms']??null,'packet_loss'=>$l['packet_loss']??null,'fiber_core'=>$l['fiber_core']??null,'fiber_type'=>$l['fiber_type']??null,'source_port'=>$l['source_port']??null,'target_port'=>$l['target_port']??null,'status'=>$l['status']??'unknown','is_published'=>(bool)($l['is_published']??false),'created_by'=>auth()->id()]);
            }
        });
        $this->reset('topologyImportFile');
        $this->message = 'Topology backup imported into the selected map.';
    }

    public function updatedSourceKey(): void { $this->source_port = ''; }
    public function updatedTargetKey(): void { $this->target_port = ''; }

    public function saveLink(): void
    {
        if ($this->mode !== 'designer') abort(403);
        $nodeOptions = $this->nodeOptions();
        $data = $this->validate([
            'source_key' => ['required', 'string', Rule::in(array_keys($nodeOptions))],
            'target_key' => ['required', 'string', Rule::in(array_keys($nodeOptions)), 'different:source_key'],
            'connection_type' => ['required', Rule::in(['uplink', 'ethernet', 'fiber_core', 'splitter', 'logical_service'])],
            'label' => ['nullable', 'string', 'max:120'],
            'capacity_mbps' => ['nullable', 'integer', 'min:1'],
            'traffic_mbps' => ['nullable', 'integer', 'min:0'],
            'latency_ms' => ['nullable', 'numeric', 'min:0'],
            'packet_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fiber_core' => ['nullable', 'integer', 'min:1'],
            'fiber_type' => ['nullable', Rule::in(['singlemode', 'multimode', 'drop', 'unknown'])],
            'source_port' => ['nullable', 'string', 'max:40'],
            'target_port' => ['nullable', 'string', 'max:40'],
        ]);

        foreach ([['key'=>$data['source_key'],'port'=>$data['source_port'],'field'=>'source_port','node_field'=>'output_ports'],['key'=>$data['target_key'],'port'=>$data['target_port'],'field'=>'target_port','node_field'=>'input_ports']] as $ep) {
            if (!$ep['port'] || !str_starts_with($ep['key'], 'topology:')) continue;
            $node = NetworkTopologyNode::find((int) str_replace('topology:', '', $ep['key']));
            if (!$node || $node->type !== 'splitter') continue;
            $capacity = (int) ($node->{$ep['node_field']} ?: ($ep['node_field'] === 'output_ports' ? $node->splitter_ratio : 1));
            $port = ($ep['field'] === 'source_port' ? 'OUT-' : 'IN-').preg_replace('/^(OUT-|IN-|P)/i', '', strtoupper(trim((string) $ep['port'])));
            $query = NetworkTopologyLink::where('map_id', $this->mapId)->where($ep['field'] === 'source_port' ? 'source_key' : 'target_key', $ep['key'])->whereNotNull($ep['field']);
            $used = $query->get()->filter(fn($l) => !($l->source_key === $data['source_key'] && $l->target_key === $data['target_key'] && $l->connection_type === $data['connection_type']))->pluck($ep['field'])->map(fn($v) => ($ep['field'] === 'source_port' ? 'OUT-' : 'IN-').preg_replace('/^(OUT-|IN-|P)/i', '', strtoupper(trim((string)$v))))->all();
            if (in_array($port, $used, true)) { $this->addError($ep['field'], 'This port is occupied. Choose a free port.'); return; }
            if ($capacity > 0 && count(array_unique($used)) >= $capacity) { $this->addError($ep['field'], 'No free ports remain on this splitter.'); return; }
            if (!preg_match('/^(OUT|IN|P)?-?([0-9]+)$/i', $port, $portMatch)) { $this->addError($ep['field'], 'Use a port such as P1, IN-1 or OUT-1.'); return; }
            $portNumber = (int) $portMatch[2];
            if ($portNumber < 1 || ($capacity > 0 && $portNumber > $capacity)) { $this->addError($ep['field'], 'Port number exceeds this splitter configured port count.'); return; }
        }

        NetworkTopologyLink::updateOrCreate(
            ['map_id' => $this->mapId, 'source_key' => $data['source_key'], 'target_key' => $data['target_key'], 'connection_type' => $data['connection_type']],
            ['label' => $data['label'] ?: null, 'capacity_mbps' => $data['capacity_mbps'] ?: null, 'traffic_mbps' => $data['traffic_mbps'] ?: null, 'latency_ms' => $data['latency_ms'] ?: null, 'packet_loss' => $data['packet_loss'] ?: null, 'fiber_core' => $data['fiber_core'] ?: null, 'fiber_type' => $data['fiber_type'] ?: null, 'source_port' => $data['source_port'] ?: null, 'target_port' => $data['target_port'] ?: null, 'status' => 'unknown', 'is_published' => false, 'created_by' => auth()->id()]
        );

        $this->reset(['source_key', 'target_key', 'label', 'capacity_mbps', 'traffic_mbps', 'latency_ms', 'packet_loss', 'fiber_core', 'source_port', 'target_port']);
        $this->fiber_type = 'singlemode';
        $this->connection_type = 'fiber_core';
        $this->message = 'Topology connection saved as a draft. Publish it to show it in Live Topology.';
    }

    public function saveNode(): void
    {
        if ($this->mode !== 'designer') abort(403);
        $data = $this->validate([
            'newNodeType' => ['required', Rule::in(['splitter', 'olt_pon', 'odf', 'rack', 'pop', 'fiber_segment', 'cabinet', 'pole', 'splice', 'server'])],
            'newNodeName' => ['required', 'string', 'max:120'],
            'newNodeLocation' => ['nullable', 'string', 'max:160'],
            'newNodeNotes' => ['nullable', 'string', 'max:1000'],
            'newNodeLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'newNodeLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'splitter_ratio' => ['nullable', 'integer', 'min:2', 'max:64'],
            'port_reference' => ['nullable', 'string', 'max:80'],
            'input_ports' => ['nullable', 'integer', 'min:1', 'max:64'],
            'output_ports' => ['nullable', 'integer', 'min:1', 'max:64'],
        ]);
        NetworkTopologyNode::create([
            'map_id' => $this->mapId, 'type' => $data['newNodeType'], 'name' => trim($data['newNodeName']),
            'location' => $data['newNodeLocation'] ?: null, 'port_reference' => $this->port_reference ?: null,
            'splitter_ratio' => $data['newNodeType'] === 'splitter' && $this->splitter_ratio !== '' ? (int) $this->splitter_ratio : null,
            'input_ports' => $data['newNodeType'] === 'splitter' ? (int) ($this->input_ports ?: 1) : null,
            'output_ports' => $data['newNodeType'] === 'splitter' ? (int) ($this->output_ports ?: ($this->splitter_ratio ?: 1)) : null,
            'notes' => $data['newNodeNotes'] ?: null,
            'latitude' => $data['newNodeLatitude'] !== '' ? (float) $data['newNodeLatitude'] : null,
            'longitude' => $data['newNodeLongitude'] !== '' ? (float) $data['newNodeLongitude'] : null,
            'is_published' => false, 'created_by' => auth()->id(),
        ]);
        $this->reset(['newNodeName', 'newNodeLocation', 'newNodeNotes', 'newNodeLatitude', 'newNodeLongitude']);
        $this->newNodeType = 'splitter';
        $this->splitter_ratio = '';
        $this->port_reference = '';
        $this->input_ports = '1';
        $this->output_ports = '';
        $this->message = 'Topology node saved as a draft. Publish it before publishing its connections.';
    }

    public function publishNode(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        NetworkTopologyNode::where('map_id', $this->mapId)->findOrFail($id)->update(['is_published' => true]);
        $this->message = 'Topology node published.';
    }

    public function unpublishNode(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        $nodeKey = 'topology:'.$id;
        $hasPublishedLinks = NetworkTopologyLink::where('map_id', $this->mapId)->where('is_published', true)
            ->where(fn ($q) => $q->where('source_key', $nodeKey)->orWhere('target_key', $nodeKey))->exists();
        if ($hasPublishedLinks) {
            $this->message = 'Unpublish or remove this node’s published connections first.';
            return;
        }
        NetworkTopologyNode::where('map_id', $this->mapId)->findOrFail($id)->update(['is_published' => false]);
        $this->message = 'Topology node moved back to draft.';
    }

    public function deleteNode(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        $nodeKey = 'topology:'.$id;
        NetworkTopologyLink::where('map_id', $this->mapId)->where(fn ($q) => $q->where('source_key', $nodeKey)->orWhere('target_key', $nodeKey))->delete();
        NetworkTopologyNode::where('map_id', $this->mapId)->findOrFail($id)->delete();
        $this->message = 'Topology node and its connections deleted.';
    }

    public function publish(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        $link = NetworkTopologyLink::where('map_id', $this->mapId)->findOrFail($id);
        foreach ([$link->source_key, $link->target_key] as $key) {
            if (str_starts_with($key, 'topology:')) {
                $node = NetworkTopologyNode::find(substr($key, 9));
                if (! $node || ! $node->is_published) {
                    $this->message = 'Publish all custom topology nodes used by this connection first.';
                    return;
                }
            }
        }
        $link->update(['is_published' => true, 'status' => 'active']);
        $this->message = 'Connection published to Live Topology.';
    }

    public function unpublish(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        NetworkTopologyLink::where('map_id', $this->mapId)->findOrFail($id)->update(['is_published' => false, 'status' => 'draft']);
        $this->message = 'Connection moved back to draft.';
    }

    public function deleteLink(int $id): void
    {
        if ($this->mode !== 'designer') abort(403);
        NetworkTopologyLink::where('map_id', $this->mapId)->findOrFail($id)->delete();
        $this->message = 'Topology connection deleted.';
    }

    public function locateTopology(string $query): void
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') return;

        // Search inventory + custom topology nodes first.
        $nodes = collect($this->baseNodes());
        $match = $nodes->first(fn($n) => str_contains(mb_strtolower((string) $n['label']), $q)
            || str_contains(mb_strtolower((string) ($n['title'] ?? '')), $q));
        if ($match) {
            $this->tracePath($match['id']);
            return;
        }

        // In Live mode also search the real ONU/customer mapping registry so an
        // operator can locate by ONU id, serial/MAC, PON, customer name or CID.
        if ($this->mode === 'live') {
            $mapping = OltOnuCustomerMapping::with(['customer', 'olt'])
                ->latest('last_seen_at')->limit(1000)->get()
                ->first(function ($m) use ($q) {
                    $values = [
                        $m->onu_id, $m->onu_serial, $m->onu_mac, $m->pon_port,
                        $m->customer?->customer_name, $m->customer?->customer_unique_id,
                        $m->olt?->name, $m->olt?->olt_name, $m->olt?->ip_address,
                    ];
                    foreach ($values as $value) {
                        if ($value !== null && str_contains(mb_strtolower((string) $value), $q)) return true;
                    }
                    return false;
                });

            if ($mapping) {
                $this->traceMappingPath($mapping);
                return;
            }
        }

        $this->dispatch('xlink-topology-locate', found: false, query: $query);
    }

    private function hasPhysicalTopologyPath($links, string $source, string $target): bool
    {
        $frontier = [$source];
        $seen = [$source => true];
        for ($round = 0; $round < 30 && $frontier; $round++) {
            $next = [];
            foreach ($links as $link) {
                if (!in_array($link->connection_type, ['fiber_core', 'splitter'], true)
                    || !in_array($link->source_key, $frontier, true)
                    || isset($seen[$link->target_key])) continue;
                if ($link->target_key === $target) return true;
                $seen[$link->target_key] = true;
                $next[] = $link->target_key;
            }
            $frontier = $next;
        }
        return false;
    }

    private function traceMappingPath(OltOnuCustomerMapping $mapping): void
    {
        $ponRef = trim((string) ($mapping->pon_port ?: 'PON'));
        $ponKey = 'pon:'.$mapping->olt_device_id.':'.preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ponRef);
        $path = [
            ['key' => 'device:'.$mapping->olt_device_id, 'label' => $mapping->olt?->name ?: 'OLT '.$mapping->olt_device_id, 'port' => $ponRef, 'connection' => 'fiber_core', 'edge' => 'auto:olt-pon:'.$ponKey],
            ['key' => $ponKey, 'label' => 'PON '.$ponRef, 'port' => $mapping->onu_id, 'connection' => 'splitter', 'edge' => 'auto:pon-onu:'.$mapping->id],
            ['key' => 'onu:'.$mapping->id, 'label' => 'ONU '.$mapping->onu_id, 'port' => 'service', 'connection' => 'logical_service', 'edge' => $mapping->customer_id ? 'auto:onu-customer:'.$mapping->id : null],
        ];
        if ($mapping->customer_id && $mapping->customer) {
            $path[] = ['key' => 'customer:'.$mapping->customer_id, 'label' => $mapping->customer->customer_name ?: $mapping->customer->customer_unique_id ?: 'Customer', 'port' => null, 'connection' => null, 'edge' => null];
        }
        $this->dispatch('xlink-topology-trace', path: $path);
    }

    public function tracePath(string $startKey): void
    {
        if ($this->mode !== 'designer' && $this->mode !== 'live') abort(403);
        $startKey = preg_replace('/^custom:/', 'topology:', $startKey);
        $links = NetworkTopologyLink::query()->where('map_id', $this->mapId)
            ->when($this->mode === 'live', fn ($q) => $q->where('is_published', true))->get();
        $nodes = collect($this->baseNodes())->keyBy('id');
        $custom = NetworkTopologyNode::query()->where('map_id', $this->mapId)
            ->when($this->mode === 'live', fn ($q) => $q->where('is_published', true))->get();
        foreach ($custom as $node) {
            $nodes->put('topology:'.$node->id, ['id'=>'topology:'.$node->id,'label'=>$node->name,'group'=>$node->type,'status'=>'unknown']);
        }
        if (!$nodes->has($startKey)) {
            $this->message = 'Trace start node was not found in the selected map.';
            return;
        }
        // Treat fiber links as bidirectional for tracing, while preserving their real direction/ports in the trace.
        $adjacency = [];
        foreach ($links as $link) {
            $adjacency[$link->source_key][] = ['next'=>$link->target_key,'link'=>$link,'forward'=>true];
            $adjacency[$link->target_key][] = ['next'=>$link->source_key,'link'=>$link,'forward'=>false];
        }
        $queue = [$startKey];
        $visited = [$startKey => true];
        $previous = [];
        while ($queue) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $edge) {
                $next = $edge['next'];
                if (isset($visited[$next]) || !$nodes->has($next)) continue;
                $visited[$next] = true;
                $previous[$next] = ['from'=>$current, 'link'=>$edge['link'], 'forward'=>$edge['forward']];
                $queue[] = $next;
            }
        }
        // Trace the selected node's connected physical/logical component, with each connection shown once.
        $path = [];
        $includedEdges = [];
        $queue = [$startKey]; $seen = [];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($seen[$current])) continue;
            $seen[$current] = true;
            $node = $nodes->get($current, []);
            $entry = ['key'=>$current,'label'=>$node['label'] ?? $current,'port'=>null,'connection'=>null,'edge'=>null];
            foreach ($adjacency[$current] ?? [] as $edge) {
                $link = $edge['link'];
                $edgeKey = 'link:'.$link->id;
                if (!isset($includedEdges[$edgeKey])) {
                    $includedEdges[$edgeKey] = true;
                    $entry['port'] = $edge['forward'] ? ($link->source_port ?: null) : ($link->target_port ?: null);
                    $entry['connection'] = $link->connection_type;
                    $entry['edge'] = $edgeKey;
                }
                if (!isset($seen[$edge['next']])) $queue[] = $edge['next'];
            }
            $path[] = $entry;
        }
        $traceEdges = $links
            ->filter(fn ($link) => isset($visited[$link->source_key], $visited[$link->target_key]))
            ->map(fn ($link) => [
                'id' => 'link:'.$link->id,
                'source' => $link->source_key,
                'target' => $link->target_key,
                'source_port' => $link->source_port,
                'target_port' => $link->target_port,
                'connection_type' => $link->connection_type,
            ])->values()->all();
        $this->dispatch('xlink-topology-trace', path: $path, edges: $traceEdges);
        $this->message = count($path) > 1
            ? 'Trace completed: '.count($path).' connected nodes and '.count($traceEdges).' connections.'
            : 'No connected path yet; add and publish the real fiber links.';
    }

    private function nodeOptions(): array
    {
        $options = [];
        foreach (RouterList::orderBy('router_name')->get() as $router) {
            $options['router:'.$router->id] = 'MikroTik · '.$router->router_name.' ('.$router->ip_address.')';
        }
        foreach (NetworkInventoryDevice::orderBy('name')->get() as $device) {
            $options['device:'.$device->id] = strtoupper(str_replace('-', ' ', $device->type)).' · '.$device->name.' ('.($device->ip_address ?: $device->host ?: 'no IP').')';
        }
        $customNodes = NetworkTopologyNode::query()->where('map_id', $this->mapId)->when($this->mode === 'live', fn ($q) => $q->where('is_published', true))->orderBy('name')->get();
        foreach ($customNodes as $node) {
            $options['topology:'.$node->id] = strtoupper(str_replace('_', ' ', $node->type)).' · '.$node->name;
        }
        // Include real OLT/PON/ONU/customer identities as selectable endpoints. This lets
        // operators draw splitter paths against the live mapping ledger without guessing IDs.
        foreach (OltOnuCustomerMapping::with(['customer', 'olt'])->latest('last_seen_at')->limit(500)->get() as $mapping) {
            if (!$mapping->olt || !$mapping->onu_id) continue;
            $oltKey = 'device:'.$mapping->olt_device_id;
            $ponRef = trim((string) ($mapping->pon_port ?: 'PON'));
            $ponKey = 'pon:'.$mapping->olt_device_id.':'.preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ponRef);
            $options[$ponKey] = 'PON · '.($mapping->olt->name ?: 'OLT '.$mapping->olt_device_id).' / '.$ponRef;
            $onuKey = 'onu:'.$mapping->id;
            $options[$onuKey] = 'ONU · '.$mapping->onu_id.' · '.($mapping->customer?->customer_name ?: $mapping->customer?->customer_unique_id ?: 'Unmapped');
            if ($mapping->customer_id && $mapping->customer) {
                $options['customer:'.$mapping->customer_id] = 'Customer · '.($mapping->customer->customer_name ?: $mapping->customer->customer_unique_id);
            }
        }
        return $options;
    }

    private function baseNodes(): array
    {
        $nodes = [];
        foreach (RouterList::orderBy('router_name')->get() as $router) {
            $nodes[] = [
                'id' => 'router:'.$router->id, 'label' => $router->router_name, 'group' => 'router',
                'status' => $router->action === 'connected' ? 'online' : (in_array($router->action, ['disconnected', 'offline'], true) ? 'offline' : 'unknown'),
                'title' => 'MikroTik · '.($router->ip_address ?: 'No management IP'),
                'url' => route('device-manager.detail', ['kind' => 'mikrotik', 'device' => $router->id]),
            ];
        }
        foreach (NetworkInventoryDevice::orderBy('name')->get() as $device) {
            $raw = strtolower(str_replace([' ', '-'], '_', (string) ($device->health_status ?: $device->status)));
            $status = in_array($raw, ['ready', 'online', 'connected', 'up'], true) ? 'online' : (in_array($raw, ['failed', 'down', 'offline', 'unreachable'], true) ? 'offline' : 'unknown');
            $nodes[] = [
                'id' => 'device:'.$device->id, 'label' => $device->name, 'group' => $device->type,
                'status' => $status, 'title' => strtoupper($device->type).' · '.($device->ip_address ?: $device->host ?: 'No management IP'),
                'url' => route('device-manager.detail', ['kind' => $device->type === 'olt' ? 'olt' : 'device', 'device' => $device->id]),
            ];
        }
        $customNodes = NetworkTopologyNode::query()->where('map_id', $this->mapId)->when($this->mode === 'live', fn ($q) => $q->where('is_published', true))->orderBy('name')->get();
        foreach ($customNodes as $node) {
            $nodes[] = [
                'id' => 'topology:'.$node->id, 'label' => $node->name, 'group' => $node->type,
                'status' => 'unknown', 'title' => strtoupper(str_replace('_', ' ', $node->type)).' · '.($node->location ?: 'Location not set'),
                'url' => null,
            ];
        }
        if ($this->mode === 'designer') {
            foreach (OltOnuCustomerMapping::with(['customer', 'olt'])->latest('last_seen_at')->limit(500)->get() as $mapping) {
                if (!$mapping->olt || !$mapping->onu_id) continue;
                $ponRef = trim((string) ($mapping->pon_port ?: 'PON'));
                $ponKey = 'pon:'.$mapping->olt_device_id.':'.preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ponRef);
                if (!collect($nodes)->contains('id', $ponKey)) $nodes[] = ['id'=>$ponKey,'label'=>'PON '.$ponRef,'group'=>'olt_pon','status'=>'unknown','title'=>($mapping->olt->name ?: 'OLT '.$mapping->olt_device_id).' · '.$ponRef,'url'=>null];
                $onuKey = 'onu:'.$mapping->id;
                $status = strtolower((string) $mapping->status);
                $status = in_array($status, ['online','up','active','connected','ready'], true) ? 'online' : (in_array($status, ['offline','down','failed','unreachable'], true) ? 'offline' : 'unknown');
                if (!collect($nodes)->contains('id', $onuKey)) $nodes[] = ['id'=>$onuKey,'label'=>'ONU '.$mapping->onu_id,'group'=>'onu','status'=>$status,'title'=>($mapping->customer?->customer_name ?: 'Unmapped ONU').' · '.($mapping->onu_serial ?: $mapping->onu_mac ?: 'No serial/MAC'),'url'=>null];
                if ($mapping->customer_id && $mapping->customer && !collect($nodes)->contains('id', 'customer:'.$mapping->customer_id)) $nodes[] = ['id'=>'customer:'.$mapping->customer_id,'label'=>$mapping->customer->customer_name ?: $mapping->customer->customer_unique_id,'group'=>'customer','status'=>$mapping->customer->status ?: 'unknown','title'=>'Customer '.$mapping->customer->customer_unique_id,'url'=>null];
            }
        }
        return $nodes;
    }

    public function render()
    {
        $nodes = $this->baseNodes();
        $query = NetworkTopologyLink::query()->where('map_id', $this->mapId)->latest();
        if ($this->mode === 'live') $query->where('is_published', true);
        $links = $query->get()->map(function ($link) {
            foreach (['source_key', 'target_key'] as $field) {
                if (str_starts_with((string) $link->{$field}, 'custom:')) {
                    $link->{$field} = 'topology:'.substr((string) $link->{$field}, 7);
                }
            }
            return $link;
        });

        $graphEdges = [];
        $nodeKeys = collect($nodes)->pluck('id')->all();
        foreach ($links as $link) {
            if (! in_array($link->source_key, $nodeKeys, true) || ! in_array($link->target_key, $nodeKeys, true)) continue;
            $graphEdges[] = [
                'id' => 'link:'.$link->id, 'from' => $link->source_key, 'to' => $link->target_key,
                'label' => $link->label ?: trim((string) (($link->source_port ?: '') . (($link->source_port || $link->target_port) ? ' → ' : '') . ($link->target_port ?: str_replace('_', ' ', $link->connection_type)))),
                'connection_type' => $link->connection_type, 'source_port' => $link->source_port, 'target_port' => $link->target_port, 'arrows' => 'to',
                'capacity_mbps' => $link->capacity_mbps, 'traffic_mbps' => $link->traffic_mbps,
                'latency_ms' => $link->latency_ms, 'packet_loss' => $link->packet_loss,
                'utilization' => ($link->capacity_mbps && $link->capacity_mbps > 0) ? round(($link->traffic_mbps ?: 0) / $link->capacity_mbps * 100, 1) : null,
                'dashes' => $link->connection_type === 'logical_service',
            ];
        }

        if ($this->mode === 'live') {
            $mappings = OltOnuCustomerMapping::with(['customer', 'olt'])->latest('last_seen_at')->limit(500)->get();
            $existing = collect($nodes)->keyBy('id');
            foreach ($mappings as $mapping) {
                if (! $mapping->olt || ! $mapping->onu_id) continue;
                $onuKey = 'onu:'.$mapping->id;
                $ponRef = trim((string) ($mapping->pon_port ?: 'PON'));
                $ponKey = 'pon:'.$mapping->olt_device_id.':'.preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ponRef);
                if (! $existing->has($ponKey)) {
                    $nodes[] = [
                        'id' => $ponKey, 'label' => 'PON '.$ponRef, 'group' => 'olt_pon', 'status' => 'online',
                        'title' => 'OLT PON Port '.$ponRef, 'url' => route('network-inventory.olt.customers', $mapping->olt_device_id),
                    ];
                    $existing->put($ponKey, true);
                    $oltKey = 'device:'.$mapping->olt_device_id;
                    if ($existing->has($oltKey)) $graphEdges[] = ['id'=>'auto:olt-pon:'.$ponKey,'from'=>$oltKey,'to'=>$ponKey,'label'=>$ponRef,'connection_type'=>'fiber_core','arrows'=>'to','capacity_mbps'=>null,'traffic_mbps'=>null,'utilization'=>null];
                }
                $customerName = $mapping->customer?->customer_name ?: $mapping->customer?->customer_unique_id ?: 'Unmapped ONU';
                $mappingStatus = strtolower(trim((string) $mapping->status));
                $status = in_array($mappingStatus, ['online', 'up', 'active', 'connected', 'ready'], true)
                    ? 'online'
                    : (in_array($mappingStatus, ['offline', 'down', 'failed', 'unreachable'], true) ? 'offline' : 'unknown');
                if ($mapping->last_seen_at && $mapping->last_seen_at->lt(now()->subMinutes(15)) && $status === 'online') {
                    $status = 'unknown';
                }
                $lastSeen = $mapping->last_seen_at?->format('Y-m-d H:i:s') ?: 'Not available';
                $nodes[] = [
                    'id' => $onuKey, 'label' => 'ONU '.$mapping->onu_id, 'group' => 'onu',
                    'status' => $status,
                    'title' => $customerName.' · PON '.($mapping->pon_port ?: '—').' · '.($mapping->onu_serial ?: $mapping->onu_mac ?: 'No serial/MAC').' · Last seen '.$lastSeen,
                    'url' => route('network-inventory.olt.customers', $mapping->olt_device_id),
                ];
                $oltKey = 'device:'.$mapping->olt_device_id;
                if ($existing->has($oltKey) && !$this->hasPhysicalTopologyPath($links, $ponKey, $onuKey)) {
                    // Keep a synthetic PON→ONU edge only until an operator has drawn the
                    // actual published fiber/splitter route. Otherwise the graph would
                    // visually bypass the very splitter chain being identified.
                    $graphEdges[] = ['id'=>'auto:pon-onu:'.$mapping->id,'from'=>$ponKey,'to'=>$onuKey,'label'=>$mapping->pon_port ?: 'ONU','connection_type'=>'splitter','arrows'=>'to','capacity_mbps'=>null,'traffic_mbps'=>null,'utilization'=>null];
                }
                if ($mapping->customer_id && $mapping->customer) {
                    $customerKey = 'customer:'.$mapping->customer_id;
                    if (! $existing->has($customerKey)) {
                        $nodes[] = [
                            'id' => $customerKey, 'label' => $customerName, 'group' => 'customer',
                            'status' => $mapping->customer->status ?: 'unknown',
                            'title' => 'Customer '.$mapping->customer->customer_unique_id,
                            'url' => route('customer.details', encrypt($mapping->customer->customer_unique_id)),
                        ];
                        $existing->put($customerKey, true);
                    }
                    $graphEdges[] = ['id'=>'auto:onu-customer:'.$mapping->id,'from'=>$onuKey,'to'=>$customerKey,'label'=>'service','connection_type'=>'logical_service','arrows'=>'to','dashes'=>true,'capacity_mbps'=>null,'traffic_mbps'=>null,'utilization'=>null];
                }
            }
        }

        // Propagate downstream alarms upstream through physical fiber/PON/splitter paths.
        $severity = ['critical' => 3, 'warning' => 2, 'online' => 0, 'unknown' => 1];
        $alarm = collect($nodes)->mapWithKeys(fn($n) => [$n['id'] => ($n['status'] === 'offline' ? 'critical' : ($n['status'] === 'unknown' ? 'unknown' : 'online'))])->all();
        for ($round = 0; $round < 20; $round++) {
            $changed = false;
            foreach ($graphEdges as $edge) {
                if (!in_array($edge['connection_type'], ['fiber_core','splitter'], true)) continue;
                $child = $alarm[$edge['to']] ?? 'unknown';
                if ($child === 'critical') {
                    $next = ($alarm[$edge['from']] ?? 'online') === 'critical' ? 'critical' : 'warning';
                    if (($severity[$next] ?? 0) > ($severity[$alarm[$edge['from']] ?? 'online'] ?? 0)) { $alarm[$edge['from']] = $next; $changed = true; }
                }
            }
            if (!$changed) break;
        }
        $nodes = array_map(function ($node) use ($alarm) {
            $node['alarm'] = $alarm[$node['id']] ?? 'online';
            return $node;
        }, $nodes);

        $branchHealth = [];
        foreach ($nodes as $node) {
            if (!in_array($node["group"], ["olt_pon", "splitter"], true)) continue;
            $reachable = [$node["id"] => true]; $frontier = [$node["id"]];
            for ($round = 0; $round < 20 && $frontier; $round++) {
                $next = [];
                foreach ($graphEdges as $edge) {
                    if (!in_array($edge["connection_type"], ["fiber_core", "splitter", "logical_service"], true) || !in_array($edge["from"], $frontier, true) || isset($reachable[$edge["to"]])) continue;
                    $reachable[$edge["to"]] = true; $next[] = $edge["to"];
                }
                $frontier = $next;
            }
            $affected = 0; $offline = 0;
            foreach ($nodes as $child) { if (!isset($reachable[$child["id"]]) || !in_array($child["group"], ["onu", "customer"], true)) continue; if (in_array($child["status"], ["offline", "unknown"], true)) $affected++; if ($child["status"] === "offline") $offline++; }
            $branchHealth[] = ["id" => $node["id"], "label" => $node["label"], "alarm" => $node["alarm"] ?? "online", "affected" => $affected, "offline" => $offline];
        }
        usort($branchHealth, fn($a, $b) => ($b["offline"] <=> $a["offline"]) ?: ($b["affected"] <=> $a["affected"]));

        $mappingHealth = null;
        if ($this->mode === 'live') {
            $mappingQuery = OltOnuCustomerMapping::query();
            $mappingHealth = [
                'total' => (clone $mappingQuery)->count(),
                'missing_olt' => (clone $mappingQuery)->whereDoesntHave('olt')->count(),
                'unmapped_customer' => (clone $mappingQuery)->whereNull('customer_id')->count(),
                'stale' => (clone $mappingQuery)->whereNotNull('last_seen_at')->where('last_seen_at', '<', now()->subMinutes(15))->count(),
                'missing_identifier' => (clone $mappingQuery)->where(function ($q) { $q->whereNull('onu_id')->orWhere('onu_id', ''); })->count(),
                'missing_identity' => (clone $mappingQuery)->where(function ($q) { $q->whereNull('onu_serial')->whereNull('onu_mac'); })->count(),
                'invalid_status' => (clone $mappingQuery)->whereNotIn('status', ['online', 'offline', 'unknown'])->count(),
            ];
        }

        $maps = NetworkTopologyMap::orderByDesc('is_default')->orderBy('name')->get();
        $nodeOptions = $this->nodeOptions();
        $customNodes = NetworkTopologyNode::query()->where('map_id', $this->mapId)->when($this->mode === 'live', fn ($q) => $q->where('is_published', true))->latest()->get();
        $sourcePortOptions = []; $targetPortOptions = [];
        foreach ($customNodes->where('type', 'splitter') as $splitter) {
            $key = 'topology:'.$splitter->id;
            $outCount = (int) ($splitter->output_ports ?: $splitter->splitter_ratio ?: 0);
            $inCount = (int) ($splitter->input_ports ?: 1);
            $usedOut = $links->where('source_key', $key)->pluck('source_port')->filter()->map(fn($v)=>'OUT-'.preg_replace('/^(OUT-|IN-|P)/i','',strtoupper(trim((string)$v))))->all();
            $usedIn = $links->where('target_key', $key)->pluck('target_port')->filter()->map(fn($v)=>'IN-'.preg_replace('/^(OUT-|IN-|P)/i','',strtoupper(trim((string)$v))))->all();
            for ($i=1; $i<=$outCount; $i++) { $port='OUT-'.$i; $sourcePortOptions[$key][$port] = $port.(in_array($port,$usedOut,true) ? ' — OCCUPIED' : ' — FREE'); }
            for ($i=1; $i<=$inCount; $i++) { $port='IN-'.$i; $targetPortOptions[$key][$port] = $port.(in_array($port,$usedIn,true) ? ' — OCCUPIED' : ' — FREE'); }
        }
        // Calculate splitter port occupancy from topology links. Each source/target port is treated as a physical port.
        $portStats = [];
        foreach ($customNodes as $splitter) {
            if ($splitter->type !== 'splitter') continue;
            $outCapacity = (int) ($splitter->output_ports ?: $splitter->splitter_ratio ?: 0);
            $inCapacity = (int) ($splitter->input_ports ?: 1);
            $usedOut = $links->filter(fn ($l) => $l->source_key === 'topology:'.$splitter->id && filled($l->source_port))->pluck('source_port')->map(fn($p) => 'OUT-'.preg_replace('/^OUT-|^P/i', '', (string)$p))->unique()->values()->all();
            $usedIn = $links->filter(fn ($l) => $l->target_key === 'topology:'.$splitter->id && filled($l->target_port))->pluck('target_port')->map(fn($p) => 'IN-'.preg_replace('/^IN-|^P/i', '', (string)$p))->unique()->values()->all();
            $portStats[$splitter->id] = ['in_capacity'=>$inCapacity,'out_capacity'=>$outCapacity,'in_used'=>count($usedIn),'out_used'=>count($usedOut),'in_free'=>max(0,$inCapacity-count($usedIn)),'out_free'=>max(0,$outCapacity-count($usedOut)),'in_ports'=>$usedIn,'out_ports'=>$usedOut];
        }
        $statusSummary = collect($nodes)->countBy('status')->all();
        return view('livewire.network-topology', compact('nodes', 'links', 'graphEdges', 'nodeOptions', 'customNodes', 'statusSummary', 'mappingHealth', 'portStats', 'sourcePortOptions', 'targetPortOptions', 'branchHealth', 'maps'))
            ->layout('layouts.app');
    }
}
