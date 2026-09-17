import { trans } from 'laravel-vue-i18n';

// Horizon counts failed jobs over a window it states itself (often a week,
// see EnvironmentData::$failedWindowMinutes). A count is shown with its own
// window and never scaled to another; warnings compare the hourly rate,
// which is what the jobs.failed_per_hour rule measures.

/** "Failed · 24h", "Failed · 7d", "Failed · 90 min"; null = windows differ. */
export function failedLabel(windowMinutes: number | null): string {
    switch (windowMinutes) {
        case null:
            return trans('Failed · mixed windows');
        case 1440:
            return trans('Failed · 24h');
        case 10080:
            return trans('Failed · 7d');
        default:
            return trans('Failed · :minutes min', {
                minutes: String(windowMinutes),
            });
    }
}

/** The sentence under a failed count; null = windows differ. */
export function failedWindowNote(windowMinutes: number | null): string {
    switch (windowMinutes) {
        case null:
            return trans('each environment over its own window');
        case 1440:
            return trans('last 24 hours');
        case 10080:
            return trans('last 7 days');
        default:
            return trans('last :minutes minutes', {
                minutes: String(windowMinutes),
            });
    }
}

/** Failures per hour, the same arithmetic as StatusEvaluator. */
export function failedPerHour(count: number, windowMinutes: number): number {
    return (count * 60) / Math.max(1, windowMinutes);
}
