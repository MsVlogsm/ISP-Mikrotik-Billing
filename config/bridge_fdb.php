<?php

return [
    // Manual live SNMP polling is opt-in. Keep disabled until a dedicated
    // read-only community and staging OLT/switch have been verified.
    'live_poll_enabled' => (bool) env('OLT_BRIDGE_FDB_POLL_ENABLED', false),
    'timeout_us' => (int) env('OLT_BRIDGE_FDB_TIMEOUT_US', 750000),
    'retries' => (int) env('OLT_BRIDGE_FDB_RETRIES', 0),
    'max_entries' => (int) env('OLT_BRIDGE_FDB_MAX_ENTRIES', 10000),
    'snapshot_retention_days' => (int) env('OLT_BRIDGE_FDB_RETENTION_DAYS', 30),
];
