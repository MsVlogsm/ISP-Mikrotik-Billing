<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OltLiveMetricSnapshot extends Model
{
    protected $fillable = [
        'olt_device_id','sampled_at','sys_descr','uptime_ticks','uptime_seconds','cpu_percent','memory_percent',
        'chassis_temp_c','interface_count','pon_up','pon_down','uplink_up','uplink_down','onu_total','onu_online',
        'onu_offline','onu_unknown','mapped_customers','capacity_total','poll_duration_ms','poll_status','metrics',
    ];

    protected $casts = [
        'sampled_at'=>'datetime','cpu_percent'=>'decimal:2','memory_percent'=>'decimal:2','chassis_temp_c'=>'decimal:2',
        'metrics'=>'array',
    ];

    public function olt(): BelongsTo { return $this->belongsTo(NetworkInventoryDevice::class, 'olt_device_id'); }
    public function ports(): HasMany { return $this->hasMany(OltLiveMetricPort::class, 'snapshot_id'); }
}
