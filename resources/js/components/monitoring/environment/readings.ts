import { trans } from 'laravel-vue-i18n';
import { exceeds, statusColor, statusLabel } from '@/lib/monitoring';

type Environment = Pick<
    App.Data.Monitoring.EnvironmentData,
    'status' | 'watched' | 'pollingEnabled'
>;

export function statusTone(environment: Environment): string {
    return environment.status
        ? statusColor(environment.status)
        : 'var(--nc-neutral-500)';
}

export function statusText(environment: Environment): string {
    if (environment.status) {
        return statusLabel(environment.status);
    }

    if (!environment.watched) {
        return trans('Not on your wall');
    }

    return environment.pollingEnabled
        ? trans('waiting for the first reading')
        : trans('Collection paused');
}

export function hasMeasurement(
    environment: Pick<App.Data.Monitoring.EnvironmentData, 'status'>,
): boolean {
    return environment.status !== null && environment.status !== 'unreachable';
}

export function pendingTone(pending: number, threshold: number | null): string {
    return exceeds(pending, threshold) ? 'var(--st-down)' : 'var(--nc-text)';
}

const SEVERITY: Record<App.Enums.EnvironmentStatus, number> = {
    inactive: 0,
    unreachable: 0,
    paused: 1,
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

export function needsAttention(
    environment: Pick<App.Data.Monitoring.EnvironmentData, 'status'>,
): boolean {
    return environment.status !== null && environment.status !== 'active';
}

export function troubledStyle(tone: string, ring = 4): Record<string, string> {
    return {
        background: `color-mix(in srgb, ${tone} 15%, var(--nc-surface))`,
        boxShadow: `var(--nc-shadow-sm), 0 0 0 ${ring}px color-mix(in srgb, ${tone} 18%, transparent)`,
    };
}

export function calmStyle(): Record<string, string> {
    return {
        background: 'var(--nc-surface)',
        boxShadow: 'var(--nc-shadow-sm)',
        opacity: '0.82',
    };
}

export function formatAge(seconds: number): string {
    const value = Math.max(0, Math.floor(seconds));

    if (value < 60) {
        return `${value} s`;
    }

    if (value < 3600) {
        return `${Math.floor(value / 60)} min`;
    }

    if (value < 172800) {
        return `${Math.floor(value / 3600)} h`;
    }

    return trans(':days d', { days: String(Math.floor(value / 86400)) });
}

export function secondsSince(iso: string, now: number): number {
    return Math.max(0, Math.floor((now - Date.parse(iso)) / 1000));
}

export function failedTone(
    failedLastHour: number,
    perHourThreshold: number | null,
): string | undefined {
    return exceeds(failedLastHour, perHourThreshold)
        ? 'var(--st-warn)'
        : undefined;
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

export function horizonStatusTone(
    environment: Pick<
        App.Data.Monitoring.EnvironmentData,
        'horizonStatus' | 'readingError'
    >,
): string {
    if (environment.readingError !== null) {
        return 'var(--nc-neutral-500)';
    }

    switch (environment.horizonStatus) {
        case 'running':
            return 'var(--st-ok)';
        case 'paused':
            return 'var(--st-warn)';
        case 'inactive':
            return 'var(--st-down)';
        default:
            return 'var(--nc-neutral-500)';
    }
}
