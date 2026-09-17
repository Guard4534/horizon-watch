<?php

return [
    'retention_days' => (int) env('HORIZON_WATCH_RETENTION_DAYS', 30),

    'alert_retention_days' => (int) env('HORIZON_WATCH_ALERT_RETENTION_DAYS', 90),

    'block_private_networks' => (bool) env('HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS', false),

    'demo_horizon_url' => env('HORIZON_WATCH_DEMO_HORIZON_URL'),

    'http_timeout_seconds' => 5,

    'read_budget_seconds' => 20,

    'stale_after_intervals' => 3,

    'test_connection_per_minute' => 10,

    'webhook_timeout_seconds' => 5,

    'webhook_response_bytes' => 65536,

    'test_notification_per_minute' => 5,
];
