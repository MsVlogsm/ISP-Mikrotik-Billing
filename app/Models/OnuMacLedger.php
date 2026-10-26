<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnuMacLedger extends Model
{
    protected $table = 'onu_mac_ledgers';

    protected $fillable = [
        'olt_device_id', 'customer_id', 'customer_unique_id', 'pon_port', 'onu_id',
        'serial_no', 'current_mac', 'previous_mac', 'service_type', 'pppoe_username',
        'static_ip', 'vlan', 'rx_power', 'tx_power', 'status', 'first_seen_at',
        'last_seen_at', 'mac_changed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'mac_changed_at' => 'datetime',
            'rx_power' => 'decimal:2',
            'tx_power' => 'decimal:2',
            'vlan' => 'integer',
        ];
    }

    public function olt()
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'olt_device_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }
}
