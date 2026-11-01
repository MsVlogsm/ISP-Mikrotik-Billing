<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkTopologyNode extends Model
{
    protected $fillable = ['map_id', 'type', 'subtype', 'name', 'location', 'port_reference', 'splitter_ratio', 'input_ports', 'output_ports', 'port_capacity', 'fiber_core_count', 'fiber_color_standard', 'latitude', 'longitude', 'notes', 'is_published', 'verification_status', 'verified_at', 'verified_by', 'created_by'];

    protected $casts = ['is_published' => 'boolean', 'verified_at' => 'datetime'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
