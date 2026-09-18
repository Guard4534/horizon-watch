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

    'horizon' => [
        'max_body_bytes' => 2 * 1024 * 1024,
        'max_masters' => 200,
        'max_supervisors' => 200,
        'max_jobs' => 200,
        'max_metric_queues' => 100,
        'metrics_concurrency' => 10,
        'exception_length' => 200,
    ],

    'readings' => [
        'poll_interval_seconds' => [
            'default' => 15,
            'min' => 15,
            'max' => 300,
        ],
        'poll_tick_seconds' => 15,
        'poll_slack_seconds' => 15,
        'poll_job_timeout_seconds' => 30,
        'poll_job_unique_seconds' => 60,
        'failed_rate_minutes' => 60,
        'series_points' => 48,
        'trend_points' => 12,
        'trend_step_seconds' => 300,
        'trend_span' => 3,
        'prune_chunk' => 10000,
    ],

    'alerts' => [
        'listed_queues' => 10,
    ],

    'notifications' => [
        'default_timezone' => 'Europe/Rome',
        'default_repeat_minutes' => 30,
        'repeat_minutes' => [15, 30, 60],
        'max_recipients' => 20,
        'mail_nodes' => 5,
        'mail_value_length' => 150,
        'resolution_catch_up_minutes' => 60,
        'delivery_tries' => 3,
        'delivery_backoff_seconds' => [10, 60],
        'delivery_timeout_seconds' => 30,
    ],

    'pages' => [
        'alerts_per_page' => 50,
        'resolved_alerts' => 5,
        'sent_notifications' => 6,
        'wall_anomalies' => 5,
    ],

    'invitations' => [
        'expires_days' => 7,
    ],

    'rate_limits' => [
        'login_per_minute' => 5,
        'two_factor_per_minute' => 5,
        'locale_per_minute' => 30,
        'setup_per_minute' => 10,
        'invitations_per_minute' => 6,
        'password_update_per_minute' => 6,
    ],
];
