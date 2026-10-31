<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ZabbixServer extends Model
{
    protected $fillable = [
        'created_by', 'name', 'api_url', 'api_token_encrypted', 'is_active',
        'api_version', 'host_count', 'problem_count', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['api_token_encrypted'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'host_count' => 'integer',
        'problem_count' => 'integer',
    ];

    public function setApiTokenAttribute(string $value): void
    {
        $this->attributes['api_token_encrypted'] = Crypt::encryptString($value);
    }

    public function getApiTokenAttribute(): ?string
    {
        $value = $this->attributes['api_token_encrypted'] ?? null;
        if (! filled($value)) return null;
        try { return Crypt::decryptString($value); } catch (\Throwable) { return null; }
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
