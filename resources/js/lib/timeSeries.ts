import { trans } from 'laravel-vue-i18n';
import { formatCount } from '@/lib/monitoring';

const COUNT_STEPS = [1, 2, 4, 6, 8];

const SECOND_STEPS = [
    1, 2, 4, 6, 10, 20, 30, 60, 120, 240, 600, 1200, 1800, 3600, 7200, 14400,
    28800, 43200, 86400,
];

const TICK_MINUTES = [15, 30, 60, 120, 180, 240, 360, 720, 1440, 2880];

type TimeTick = { at: number; label: string };

export function niceCountMax(value: number): number {
    if (value <= 1) {
        return 1;
    }

    for (let magnitude = 1; ; magnitude *= 10) {
        const step = COUNT_STEPS.find((base) => base * magnitude >= value);

        if (step !== undefined) {
            return step * magnitude;
        }
    }
}

export function niceSecondsMax(value: number): number {
    const step = SECOND_STEPS.find((candidate) => candidate >= value);

    if (step !== undefined) {
        return step;
    }

    return Math.ceil(value / 86400 / 2) * 2 * 86400;
}

export function axisTicks(max: number): number[] {
    const middle = max / 2;

    return Number.isInteger(middle) ? [0, middle, max] : [0, max];
}

export function formatAxisCount(value: number): string {
    return formatCount(value).replace(/\.0k$/, 'k');
}

export function spansDays(spanSeconds: number): boolean {
    return spanSeconds > 2 * 86400;
}

function formatDay(at: Date, locale: string): string {
    const parts = new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        day: 'numeric',
    }).formatToParts(at);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((candidate) => candidate.type === type)?.value ?? '';

    return `${part('weekday')} ${part('day')}`;
}

function formatClock(at: Date, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(at);
}

function formatTickTime(at: Date, locale: string, withDay: boolean): string {
    return withDay ? formatDay(at, locale) : formatClock(at, locale);
}

export function formatBucketTime(
    at: Date,
    locale: string,
    withDay: boolean,
): string {
    return withDay
        ? `${formatDay(at, locale)} ${formatClock(at, locale)}`
        : formatClock(at, locale);
}

export function timeTicks(
    from: number,
    until: number,
    maxLabels: number,
    locale: string,
): TimeTick[] {
    const withDay = spansDays((until - from) / 1000);
    const candidates = TICK_MINUTES.filter((minutes) =>
        withDay ? minutes >= 1440 : minutes < 1440,
    );

    let chosen: number[] = [];

    for (const minutes of candidates) {
        chosen = localTicks(from, until, minutes);

        if (chosen.length <= maxLabels) {
            break;
        }
    }

    return chosen.map((at) => ({
        at,
        label: formatTickTime(new Date(at), locale, withDay),
    }));
}

function localTicks(from: number, until: number, minutes: number): number[] {
    const origin = new Date(from);
    const ticks: number[] = [];

    for (let index = 0; ; index++) {
        const at = new Date(
            origin.getFullYear(),
            origin.getMonth(),
            origin.getDate(),
            0,
            index * minutes,
        ).getTime();

        if (at > until) {
            return ticks;
        }

        if (at >= from) {
            ticks.push(at);
        }
    }
}

export function rangeLabel(range: App.Enums.SeriesRange): string {
    switch (range) {
        case '3h':
            return trans('last 3 hours');
        case '24h':
            return trans('last 24 hours');
        case '7d':
            return trans('last 7 days');
    }
}
