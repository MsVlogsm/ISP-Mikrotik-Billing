<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationSyncStatus extends Model
{
    protected $fillable = [
        'task_key',
        'label',
        'cadence',
        'status',
        'last_started_at',
        'last_success_at',
        'last_failed_at',
        'runs_count',
        'failures_count',
        'last_duration_ms',
        'last_message',
        'last_metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_metadata' => 'array',
            'runs_count' => 'integer',
            'failures_count' => 'integer',
            'last_duration_ms' => 'integer',
        ];
    }
}