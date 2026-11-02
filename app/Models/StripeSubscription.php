<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeSubscription extends Model
{
    protected $fillable = [
        'customer_unique_id', 'stripe_customer_id', 'stripe_subscription_id',
        'checkout_session_id', 'checkout_idempotency_key', 'package_id',
        'stripe_price_id', 'status', 'currency', 'unit_amount',
        'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'canceled_at', 'last_invoice_id', 'checkout_expires_at',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
        'checkout_expires_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(PackageList::class, 'package_id');
    }
}
