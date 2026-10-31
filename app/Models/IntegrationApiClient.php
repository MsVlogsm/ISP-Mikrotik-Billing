<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationApiClient extends Model
{
    protected $fillable = [
        'user_id', 'name', 'client_id', 'client_secret_hash', 'scopes',
        'rate_limit_per_minute', 'is_active', 'last_used_at',
    ];

    protected $hidden = ['client_secret_hash'];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'rate_limit_per_minute' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
