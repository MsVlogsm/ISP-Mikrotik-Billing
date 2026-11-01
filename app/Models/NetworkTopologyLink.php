<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkTopologyLink extends Model
{
    protected $fillable = [
        'map_id', 'source_key', 'target_key', 'connection_type', 'label', 'capacity_mbps',
        'traffic_mbps', 'latency_ms', 'packet_loss', 'fiber_core', 'source_core', 'source_core_color', 'source_core_hex', 'target_core', 'target_core_color', 'target_core_hex', 'fiber_type', 'fiber_color_standard', 'fiber_core_color', 'fiber_core_color_hex', 'fiber_tube_number', 'fiber_tube_color', 'source_port', 'target_port',
        'status', 'is_published', 'verification_status', 'verified_at', 'verified_by', 'created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean', 'verified_at' => 'datetime', 'capacity_mbps' => 'integer', 'traffic_mbps' => 'integer',
        'latency_ms' => 'decimal:2', 'packet_loss' => 'decimal:2', 'fiber_core' => 'integer', 'source_core' => 'integer', 'target_core' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
