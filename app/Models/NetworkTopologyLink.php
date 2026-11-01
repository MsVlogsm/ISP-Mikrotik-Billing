<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkTopologyLink extends Model
{
    protected $fillable = [
        'map_id', 'source_key', 'target_key', 'connection_type', 'label', 'capacity_mbps',
        'traffic_mbps', 'latency_ms', 'packet_loss', 'fiber_core', 'fiber_type', 'fiber_color_standard', 'fiber_core_color', 'fiber_core_color_hex', 'fiber_tube_number', 'fiber_tube_color', 'source_port', 'target_port',
        'status', 'is_published', 'created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean', 'capacity_mbps' => 'integer', 'traffic_mbps' => 'integer',
        'latency_ms' => 'decimal:2', 'packet_loss' => 'decimal:2', 'fiber_core' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
