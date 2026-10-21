import { trans } from 'laravel-vue-i18n';
import { formatInstant } from '@/lib/datetime';

export function maintenanceUntilText(iso: string, locale: string): string {
    return trans('In maintenance until :time', {
        time: formatInstant(iso, locale),
    });
}

export function silenceDurationLabel(minutes: number): string {
    if (minutes % 60 !== 0) {
        return trans('For :minutes minutes', { minutes: String(minutes) });
    }

    return minutes === 60
        ? trans('For 1 hour')
        : trans('For :hours hours', { hours: String(minutes / 60) });
}

export function toLocalInput(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function fromLocalInput(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toISOString();
}
