<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthProvider extends Model
{
    protected $fillable = ['code', 'name', 'contact_name', 'phone', 'email', 'status', 'notes'];
    public function transactions() { return $this->hasMany(BandwidthTransaction::class, 'provider_id'); }
}
