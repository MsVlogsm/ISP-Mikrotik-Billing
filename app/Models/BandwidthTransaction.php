<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthTransaction extends Model
{
    protected $fillable = ['direction', 'provider_id', 'bandwidth_reseller_id', 'reference', 'bandwidth_mbps', 'unit_rate', 'total_amount', 'paid_amount', 'transaction_date', 'billing_period', 'status', 'notes'];
    protected $casts = ['bandwidth_mbps' => 'decimal:3', 'unit_rate' => 'decimal:4', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'transaction_date' => 'date'];
    public function provider() { return $this->belongsTo(BandwidthProvider::class, 'provider_id'); }
    public function reseller() { return $this->belongsTo(BandwidthReseller::class, 'bandwidth_reseller_id'); }
    public function getBalanceAttribute(): float { return max(0, (float) $this->total_amount - (float) $this->paid_amount); }
}
