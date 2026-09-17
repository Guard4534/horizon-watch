import { trans } from 'laravel-vue-i18n';

// Horizon counts failed jobs over a window it states itself (often a week,
// see EnvironmentData::$failedWindowMinutes). A count is shown with its own
// window and never scaled to another.

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

/** "24h", "7d", "90 min": the window alone, beside a count. */
export function failedWindowShort(windowMinutes: number): string {
    switch (windowMinutes) {
        case 1440:
            return '24h';
        case 10080:
            return trans(':days d', { days: '7' });
        default:
            return `${windowMinutes} min`;
    }
}
