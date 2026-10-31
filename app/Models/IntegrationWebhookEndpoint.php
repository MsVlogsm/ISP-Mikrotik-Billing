<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class IntegrationWebhookEndpoint extends Model
{
    protected $fillable = ['user_id', 'name', 'url', 'secret_encrypted', 'events', 'is_active', 'last_delivered_at', 'last_failed_at'];

    protected $hidden = ['secret_encrypted'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_delivered_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function setSecretAttribute(string $value): void
    {
        $this->attributes['secret_encrypted'] = Crypt::encryptString($value);
    }

    public function getSecretAttribute(): ?string
    {
        $value = $this->attributes['secret_encrypted'] ?? null;
        if (! filled($value)) return null;
        try { return Crypt::decryptString($value); } catch (\Throwable) { return null; }
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationWebhookDelivery::class, 'webhook_endpoint_id');
    }
}
