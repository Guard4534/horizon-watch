export type EnvironmentStatus =
    | 'active'
    | 'degraded'
    | 'paused'
    | 'inactive'
    | 'unreachable';

export type EnvironmentColor =
    | 'prod'
    | 'preprod'
    | 'staging'
    | 'develop'
    | 'demo'
    | 'worker'
    | 'testing';

export type AlertSeverity = 'warning' | 'critical';
