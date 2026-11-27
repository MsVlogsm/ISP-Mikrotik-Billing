<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikBridgeHostEntry extends Model
{
    protected $table = 'mikrotik_bridge_host_entries';

    protected $fillable = [
        'snapshot_id',
        'router_entry_id',
        'mac_address',
        'vlan_id',
        'bridge_name',
        'interface_name',
        'is_dynamic',
        'is_local',
        'is_external',
        'is_disabled',
        'is_invalid',
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'is_local' => 'boolean',
        'is_external' => 'boolean',
        'is_disabled' => 'boolean',
        'is_invalid' => 'boolean',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MikrotikBridgeHostSnapshot::class, 'snapshot_id');
    }
}
