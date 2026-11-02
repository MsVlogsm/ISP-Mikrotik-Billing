<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeCustomerLink extends Model
{
    protected $fillable = ['customer_unique_id', 'stripe_customer_id'];

    public function subscriptions()
    {
        return $this->hasMany(StripeSubscription::class, 'customer_unique_id', 'customer_unique_id');
    }
}
