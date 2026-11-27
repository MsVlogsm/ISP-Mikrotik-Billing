<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikBridgeHostMovement extends Model
{
    protected $table = 'mikrotik_bridge_host_movements';

    protected $fillable = [
        'router_list_id',
        'snapshot_id',
        'previous_snapshot_id',
        'mac_address',
        'vlan_id',
        'from_bridge',
        'to_bridge',
        'from_interface',
        'to_interface',
        'moved_at',
        'moves_in_10m',
        'is_flapping',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
        'is_flapping' => 'boolean',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(RouterList::class, 'router_list_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MikrotikBridgeHostSnapshot::class, 'snapshot_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(MikrotikBridgeHostSnapshot::class, 'previous_snapshot_id');
    }
}
