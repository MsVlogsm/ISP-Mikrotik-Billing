<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltMonitoringSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'olt_device_id',
        'sampled_at',
        'onu_total',
        'onu_online',
        'onu_offline',
        'onu_unknown',
        'mapped_onus',
        'mapped_customers',
        'rx_sample_count',
        'signal_good',
        'signal_warning',
        'signal_critical',
        'signal_no_sample',
        'avg_rx_power',
        'min_rx_power',
        'avg_latency_ms',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'avg_rx_power' => 'decimal:2',
        'min_rx_power' => 'decimal:2',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'olt_device_id');
    }
}
