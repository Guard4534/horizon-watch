import { trans } from 'laravel-vue-i18n';
import { failedPerHour } from '@/lib/failedWindow';
import { statusColor, statusLabel } from '@/lib/monitoring';

// Presentation rules for stored readings, shared by the application and
// environment pages (desktop and mobile).

type Environment = Pick<
    App.Data.Monitoring.EnvironmentData,
    'status' | 'watched'
>;

/**
 * A null status says nothing about the environment's health: either the
 * viewer does not watch it (the row carries no reading at all) or no reading
 * has landed yet. Neither is drawn in a status colour.
 */
export function statusTone(environment: Environment): string {
    return environment.status
        ? statusColor(environment.status)
        : 'var(--nc-neutral-500)';
}

export function statusText(environment: Environment): string {
    if (environment.status) {
        return statusLabel(environment.status);
    }

    return environment.watched
        ? trans('waiting for the first reading')
        : trans('Not on your wall');
}

/** Down, degraded or paused: the states that tint a card. */
export function needsAttention(environment: Environment): boolean {
    return environment.status !== null && environment.status !== 'active';
}

/** "12 s", "4 min", "3 h", "2 d": the age of a reading, never negative. */
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

/**
 * The same count means very different things over a day and over a week:
 * the warning follows the hourly rate, as the jobs.failed_per_hour rule does.
 * The label is failedLabel() from @/lib/failedWindow.
 */
export function failedColor(
    count: number,
    windowMinutes: number,
    perHourThreshold: number,
): string {
    return failedPerHour(count, windowMinutes) > perHourThreshold
        ? 'var(--st-warn)'
        : 'var(--nc-text)';
}

/** "15s", "1 min", "5 min": a poll interval as the mockup writes it. */
export function formatInterval(seconds: number): string {
    return seconds < 60 || seconds % 60 !== 0
        ? `${seconds}s`
        : `${seconds / 60} min`;
}
