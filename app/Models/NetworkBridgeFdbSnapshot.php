<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkBridgeFdbSnapshot extends Model
{
    protected $table = 'network_bridge_fdb_snapshots';

    protected $fillable = [
        'network_inventory_device_id',
        'sampled_at',
        'source',
        'status',
        'entry_count',
        'learned_count',
        'other_count',
        'self_count',
        'management_count',
        'invalid_count',
        'poll_duration_ms',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'network_inventory_device_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(NetworkBridgeFdbEntry::class, 'snapshot_id');
    }
}
