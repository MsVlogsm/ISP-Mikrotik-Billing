<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OltOnuCustomerMapping extends Model
{
    protected $fillable = [
        'olt_device_id', 'customer_id', 'ppp_user_id', 'onu_id', 'onu_serial', 'onu_mac',
        'pon_port', 'distance_m', 'temperature', 'supply_voltage', 'tx_bias_current',
        'onu_type', 'status', 'rx_power', 'tx_power', 'opm_source', 'opm_last_seen_at',
        'onu_ip', 'last_seen_at', 'notes',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'opm_last_seen_at' => 'datetime',
        'distance_m' => 'decimal:2',
        'temperature' => 'decimal:2',
        'supply_voltage' => 'decimal:3',
        'tx_bias_current' => 'decimal:2',
        'rx_power' => 'decimal:2',
        'tx_power' => 'decimal:2',
    ];

    public function olt()
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'olt_device_id');
    }

    /** Legacy primary subscriber (kept for backward compatibility). */
    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }

    /** Legacy primary PPP account (kept for backward compatibility). */
    public function pppUser()
    {
        return $this->belongsTo(PPPSecrets::class, 'ppp_user_id');
    }

    /** All customer/PPPoE subscribers sharing this physical ONU. */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(
            CustomersInfo::class,
            'olt_onu_customer_ppp_links',
            'olt_onu_customer_mapping_id',
            'customer_id'
        )->withPivot([
            'ppp_user_id', 'client_mac', 'source', 'status', 'first_seen_at', 'last_seen_at', 'notes',
        ])->withTimestamps();
    }

    public function subscriberLinks(): HasMany
    {
        return $this->hasMany(OltOnuCustomerPppLink::class, 'olt_onu_customer_mapping_id');
    }
}
