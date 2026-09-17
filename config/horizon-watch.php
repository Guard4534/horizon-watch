<?php

return [

    // How long raw readings are kept; monitoring:prune deletes older ones.
    'retention_days' => (int) env('HORIZON_WATCH_RETENTION_DAYS', 30),

    // Private and loopback addresses are allowed by default, because the
    // typical Horizon is an internal one. Cloud metadata addresses are
    // blocked whatever this says.
    'block_private_networks' => (bool) env('HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS', false),

    // Development only: the seeder adds a "Local Horizon" application that
    // polls this URL. Inside Sail the host machine is host.docker.internal.
    'demo_horizon_url' => env('HORIZON_WATCH_DEMO_HORIZON_URL'),

    'http_timeout_seconds' => 5,

    // An environment without a reading for this many poll intervals is
    // shown as not up to date.
    'stale_after_intervals' => 3,

    'test_connection_per_minute' => 10,

];
