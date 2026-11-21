<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkBridgeFdbMovement extends Model
{
    protected $table = 'network_bridge_fdb_movements';

    protected $fillable = [
        'network_inventory_device_id',
        'snapshot_id',
        'previous_snapshot_id',
        'mac_address',
        'vlan_id',
        'from_interface',
        'to_interface',
        'from_if_index',
        'to_if_index',
        'moved_at',
        'moves_in_10m',
        'is_flapping',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
        'is_flapping' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'network_inventory_device_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NetworkBridgeFdbSnapshot::class, 'snapshot_id');
    }
}
