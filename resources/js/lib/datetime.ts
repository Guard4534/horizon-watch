import { trans } from 'laravel-vue-i18n';

/** An instant in the viewer's zone: the time today, weekday, day and month otherwise. */
export function formatInstant(
    iso: string,
    locale: string,
    now: Date = new Date(),
): string {
    const at = new Date(iso);
    const today = at.toDateString() === now.toDateString();

    return new Intl.DateTimeFormat(locale, {
        ...(today ? {} : { weekday: 'short', day: 'numeric', month: 'short' }),
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(at);
}

export function quietHoursSummary(
    from: string | null,
    to: string | null,
    timezone: string,
): string {
    return from && to ? `${from} → ${to} · ${timezone}` : trans('None');
}

export function browserTimeZone(): string {
    return new Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}
