import { trans } from 'laravel-vue-i18n';
import { severityColor } from '@/lib/alerts';
import { formatCount, statusColor, statusLabel } from '@/lib/monitoring';

export type DisplayState =
    | 'hidden'
    | 'collection_off'
    | 'reading_error'
    | 'stale'
    | 'waiting'
    | App.Enums.EnvironmentStatus;

type StateFields = Pick<
    App.Data.Monitoring.EnvironmentData,
    'status' | 'watched' | 'pollingEnabled' | 'readingError' | 'stale'
>;

const CALM: ReadonlyArray<DisplayState> = [
    'hidden',
    'collection_off',
    'waiting',
    'active',
];

export function displayState(environment: StateFields): DisplayState {
    if (!environment.watched) {
        return 'hidden';
    }

    if (!environment.pollingEnabled) {
        return 'collection_off';
    }

    if (environment.readingError !== null) {
        return 'reading_error';
    }

    if (environment.status === 'stale') {
        return 'stale';
    }

    return environment.status ?? 'waiting';
}

export function statusText(environment: StateFields): string {
    const state = displayState(environment);

    switch (state) {
        case 'hidden':
            return trans('Not on your wall');
        case 'collection_off':
            return trans('Collection paused');
        case 'reading_error':
            return statusLabel('unreachable');
        case 'waiting':
            return trans('No reading yet');
        default:
            return statusLabel(state);
    }
}

export function statusTone(environment: StateFields): string {
    const state = displayState(environment);

    switch (state) {
        case 'hidden':
        case 'collection_off':
        case 'waiting':
            return 'var(--nc-neutral-500)';
        case 'reading_error':
            return statusColor('unreachable');
        default:
            return statusColor(state);
    }
}

export function isHealthy(environment: StateFields): boolean {
    return displayState(environment) === 'active';
}

export function needsAttention(environment: StateFields): boolean {
    return !CALM.includes(displayState(environment));
}

export function readingErrorLabel(error: App.Enums.ReadingError): string {
    switch (error) {
        case 'unreachable':
            return trans('Horizon does not answer');
        case 'unauthorized':
            return trans('Horizon refused the credentials');
        case 'not_horizon':
            return trans('The address does not answer like Horizon');
        case 'blocked':
            return trans('This address is not allowed');
        case 'password_unreadable':
            return trans(
                'The stored password cannot be decrypted: enter it again',
            );
    }
}

export function hasMeasurement(
    environment: Pick<
        App.Data.Monitoring.EnvironmentData,
        'status' | 'lastReadingAt' | 'readingError'
    >,
): boolean {
    return (
        environment.lastReadingAt !== null &&
        environment.readingError === null &&
        environment.status !== null &&
        environment.status !== 'unreachable'
    );
}

export function thresholdTone(
    environment: Pick<
        App.Data.Monitoring.EnvironmentData,
        'thresholds' | 'pollingEnabled'
    >,
    metric: App.Enums.AlertRuleMetric,
    value: number,
): string | undefined {
    if (!environment.pollingEnabled) {
        return undefined;
    }

    const threshold = environment.thresholds[metric] as
        | App.Data.Monitoring.ThresholdData
        | undefined;

    return threshold && value > threshold.value
        ? severityColor(threshold.severity)
        : undefined;
}

export function failedLastHourText(
    environment: Pick<
        App.Data.Monitoring.EnvironmentData,
        'failedLastHour' | 'failedLastHourCapped'
    >,
): string {
    const count = formatCount(environment.failedLastHour);

    return environment.failedLastHourCapped
        ? trans('at least :count', { count })
        : count;
}

const SEVERITY: Record<App.Enums.EnvironmentStatus, number> = {
    inactive: 0,
    unreachable: 0,
    paused: 1,
    stale: 1,
    degraded: 2,
    active: 3,
};

export function worstTone(
    environments: Pick<App.Data.Monitoring.EnvironmentData, 'status'>[],
): string | null {
    const statuses = environments
        .map((environment) => environment.status)
        .filter((status): status is App.Enums.EnvironmentStatus => !!status)
        .sort((a, b) => SEVERITY[a] - SEVERITY[b]);

    return statuses[0] ? statusColor(statuses[0]) : null;
}

export function troubledStyle(tone: string): Record<string, string> {
    return {
        background: `color-mix(in srgb, ${tone} 13%, var(--nc-surface))`,
        boxShadow: `0 0 0 1px color-mix(in srgb, ${tone} 38%, transparent)`,
    };
}

export function calmStyle(): Record<string, string> {
    return {
        background: 'var(--nc-surface)',
        boxShadow: 'var(--nc-shadow-sm)',
        opacity: '0.82',
    };
}

export function secondsSince(iso: string, now: number): number {
    return Math.max(0, Math.floor((now - Date.parse(iso)) / 1000));
}

export function horizonStatusLabel(status: App.Enums.HorizonStatus): string {
    switch (status) {
        case 'running':
            return trans('running');
        case 'paused':
            return trans('paused');
        case 'inactive':
            return trans('inactive');
    }
}

const HORIZON_STATUS: Record<
    App.Enums.HorizonStatus,
    App.Enums.EnvironmentStatus
> = {
    running: 'active',
    paused: 'paused',
    inactive: 'inactive',
};

export function horizonStatusTone(
    environment: Pick<
        App.Data.Monitoring.EnvironmentData,
        'horizonStatus' | 'readingError' | 'pollingEnabled'
    >,
): string {
    if (
        !environment.pollingEnabled ||
        environment.readingError !== null ||
        environment.horizonStatus === null
    ) {
        return 'var(--nc-neutral-500)';
    }

    return statusColor(HORIZON_STATUS[environment.horizonStatus]);
}

export function readingProblemLabel(
    problem: App.Data.Monitoring.ReadingProblemData,
): string {
    switch (problem.cause) {
        case 'timeout':
            return trans('The connection timed out');
        case 'connection_failed':
            return trans('Could not connect to the server');
        case 'tls':
            return trans('The TLS handshake or certificate check failed');
        case 'dns':
            return trans('The host name does not resolve');
        case 'redirect':
            return problem.location
                ? trans('Redirected to :location', {
                      location: problem.location,
                  })
                : trans('The address redirects elsewhere');
        case 'not_found':
            return trans(
                'Nothing at this path (HTTP 404): check the Horizon URL',
            );
        case 'http_status':
            return trans('HTTP :status', {
                status: String(problem.status ?? '—'),
            });
        case 'too_large':
            return trans('The answer is larger than 2 MB');
        case 'compressed':
            return trans(
                'The answer is compressed although no compression was asked for',
            );
        case 'not_json':
            return trans('The answer is a web page, not the Horizon API');
        case 'unexpected_json':
            return trans('The answer is JSON, but not what Horizon sends');
        case 'poll_failed':
            return trans(
                "The reading did not finish: see the log of the panel's worker",
            );
    }
}
