<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripePackagePrice extends Model
{
    protected $fillable = [
        'package_id', 'stripe_product_id', 'stripe_price_id', 'currency',
        'unit_amount', 'recurring_interval', 'interval_count', 'is_active', 'verified_at',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
        'interval_count' => 'integer',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(PackageList::class, 'package_id');
    }
}
