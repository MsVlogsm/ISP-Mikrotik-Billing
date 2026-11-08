<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OltOnuCustomerPppLink extends Model
{
    protected $table = 'olt_onu_customer_ppp_links';

    protected $fillable = [
        'olt_onu_customer_mapping_id',
        'customer_id',
        'ppp_user_id',
        'client_mac',
        'source',
        'status',
        'first_seen_at',
        'last_seen_at',
        'notes',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function onuMapping()
    {
        return $this->belongsTo(OltOnuCustomerMapping::class, 'olt_onu_customer_mapping_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }

    public function pppUser()
    {
        return $this->belongsTo(PPPSecrets::class, 'ppp_user_id');
    }
}
