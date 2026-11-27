<?php

return [
    'scheduled_poll_enabled' => (bool) env('MIKROTIK_BRIDGE_HOST_SCHEDULE_ENABLED', true),
    'timeout_seconds' => (int) env('MIKROTIK_BRIDGE_HOST_TIMEOUT_SECONDS', 8),
    'max_entries' => (int) env('MIKROTIK_BRIDGE_HOST_MAX_ENTRIES', 10000),
    'snapshot_retention_days' => (int) env('MIKROTIK_BRIDGE_HOST_RETENTION_DAYS', 30),
];
