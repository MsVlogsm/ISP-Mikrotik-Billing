<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationWebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_endpoint_id', 'event', 'payload', 'status', 'attempts',
        'response_status', 'response_excerpt', 'last_error', 'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'attempts' => 'integer',
        'response_status' => 'integer',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationWebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
