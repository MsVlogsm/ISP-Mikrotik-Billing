<?php

return [
    // RX thresholds are consistent with the existing ONU Management signal legend.
    'signal_good_min_dbm' => -20.0,
    'signal_warning_min_dbm' => -27.0,
    // A saved OPM value is shown as current only when it was measured recently.
    'opm_fresh_minutes' => 30,
    // One snapshot per OLT is updated for five minutes, then a new trend point is stored.
    'snapshot_interval_minutes' => 5,
    'snapshot_retention_days' => 90,
    // Live telemetry alarms evaluate only real numeric readings in the saved snapshot.
    'live_alerts' => [
        'cpu_percent' => ['warning' => 85.0, 'critical' => 95.0],
        'memory_percent' => ['warning' => 90.0, 'critical' => 97.0],
        'onu_rx_dbm' => ['warning' => -20.0, 'critical' => -27.0],
    ],
];
