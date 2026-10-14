<?php

return [
    'retention_days' => max(7, (int) env('HORIZON_WATCH_RETENTION_DAYS', 30)),

    'alert_retention_days' => max(1, (int) env('HORIZON_WATCH_ALERT_RETENTION_DAYS', 90)),

    'failed_job_retention_hours' => 168,

    'block_private_networks' => (bool) env('HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS', false),

    'organization_creation' => (string) env('HORIZON_WATCH_ORG_CREATION', 'admins'),

    'setup_token' => trim((string) env('HORIZON_WATCH_SETUP_TOKEN', '')),

    'demo_horizon_url' => env('HORIZON_WATCH_DEMO_HORIZON_URL'),

    'http_timeout_seconds' => 5,

    'read_budget_seconds' => 20,

    'stale_after_intervals' => 3,

    'version' => (string) (env('HORIZON_WATCH_VERSION') ?: 'dev'),

    'health' => [
        'scheduler_late_seconds' => 120,
        'backlog_late_seconds' => 300,
        'worker_late_seconds' => 120,
        'worker_beat_seconds' => 15,
        'heartbeat_url' => (string) env('HORIZON_WATCH_HEARTBEAT_URL', ''),
        'heartbeat_timeout_seconds' => 5,
    ],

    'webhook_timeout_seconds' => 5,

    'webhook_response_bytes' => 65536,

    'horizon' => [
        'max_body_bytes' => 2 * 1024 * 1024,
        'max_masters' => 200,
        'max_supervisors' => 200,
        'max_queues' => 500,
        'max_jobs' => 200,
        'max_job_pages' => 4,
        'max_metric_queues' => 100,
        'metrics_concurrency' => 10,
        'runtime_refresh_seconds' => 300,
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
        'purge_budget_seconds' => 10.0,
    ],

    'alerts' => [
        'listed_queues' => 10,
        'silence_minutes' => [30, 60, 120, 240],
        'maintenance_max_days' => 31,
    ],

    'notifications' => [
        'default_timezone' => (string) env('HORIZON_WATCH_DEFAULT_TIMEZONE', 'UTC'),
        'default_repeat_minutes' => 30,
        'repeat_minutes' => [15, 30, 60],
        'max_recipients' => 20,
        'mail_nodes' => 5,
        'mail_value_length' => 150,
        'resolution_catch_up_minutes' => 60,
        'alerts_per_run' => 500,
        'delivery_tries' => 3,
        'delivery_backoff_seconds' => [10, 60],
        'delivery_timeout_seconds' => 30,
        'retry_after_max_seconds' => 300,
        'previous_webhook_secret_hours' => 24,
    ],

    'pages' => [
        'alerts_per_page' => 50,
        'resolved_alerts' => 5,
        'sent_notifications' => 6,
        'wall_anomalies' => 5,
        'ended_maintenance_windows' => 5,
    ],

    'invitations' => [
        'expires_days' => 7,
    ],

    'limits' => [
        'wizard_environments' => 20,
        'environment_ids' => 1000,
    ],

    'rate_limits' => [
        'login_per_minute' => 5,
        'login_per_account_per_hour' => 30,
        'two_factor_per_minute' => 5,
        'password_reset_per_minute' => 5,
        'password_reset_per_account_per_hour' => 5,
        'current_password_per_minute' => 6,
        'locale_per_minute' => 30,
        'setup_per_minute' => 10,
        'invitations_per_minute' => 6,
        'password_update_per_minute' => 6,
        'test_connection_per_minute' => 10,
        'test_notification_per_minute' => 5,
    ],
];
