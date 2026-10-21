import { trans } from 'laravel-vue-i18n';
import { formatSeconds } from '@/lib/monitoring';

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

export function failedWindowShort(windowMinutes: number): string {
    return formatSeconds(windowMinutes * 60);
}
