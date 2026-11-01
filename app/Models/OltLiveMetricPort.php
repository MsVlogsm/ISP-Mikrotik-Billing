<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltLiveMetricPort extends Model
{
    protected $fillable = [
        'snapshot_id','olt_device_id','if_index','interface_name','role','oper_status','in_octets','out_octets',
        'in_bps','out_bps','in_errors','out_errors','in_discards','out_discards','in_packets','out_packets','packet_health_percent','onu_total','onu_online','rx_sample_count','avg_rx_dbm','avg_tx_dbm','avg_distance_m','sampled_at',
    ];

    protected $casts = [
        'sampled_at'=>'datetime','in_bps'=>'decimal:2','out_bps'=>'decimal:2','packet_health_percent'=>'decimal:4','avg_rx_dbm'=>'decimal:2',
        'avg_tx_dbm'=>'decimal:2','avg_distance_m'=>'decimal:2',
    ];

    public function snapshot(): BelongsTo { return $this->belongsTo(OltLiveMetricSnapshot::class, 'snapshot_id'); }
}
