<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MikrotikBridgeHostSnapshot extends Model
{
    protected $table = 'mikrotik_bridge_host_snapshots';

    protected $fillable = [
        'router_list_id',
        'sampled_at',
        'source',
        'status',
        'entry_count',
        'dynamic_count',
        'local_count',
        'external_count',
        'disabled_count',
        'invalid_count',
        'poll_duration_ms',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(RouterList::class, 'router_list_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MikrotikBridgeHostEntry::class, 'snapshot_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MikrotikBridgeHostMovement::class, 'snapshot_id');
    }
}
