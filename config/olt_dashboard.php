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
];
