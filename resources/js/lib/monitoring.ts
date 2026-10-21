import {
    PhClockCountdown,
    PhPauseCircle,
    PhPlugs,
    PhWarning,
    PhWarningOctagon,
} from '@phosphor-icons/vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

const SERIES_REFRESH_PER_STEP = 4;
const SERIES_REFRESH_MAX_MS = 300000;

const STATUS_COLORS: Record<App.Enums.EnvironmentStatus, string> = {
    active: 'var(--st-ok)',
    degraded: 'var(--st-warn)',
    paused: 'var(--st-off)',
    inactive: 'var(--st-down)',
    unreachable: 'var(--st-down)',
    stale: 'var(--st-warn)',
};

export function statusColor(status: App.Enums.EnvironmentStatus): string {
    return STATUS_COLORS[status];
}

export function envTypeColor(type: App.Enums.EnvironmentType): string {
    return `var(--env-${type})`;
}

export function statusLabel(status: App.Enums.EnvironmentStatus): string {
    switch (status) {
        case 'active':
            return trans('active');
        case 'degraded':
            return trans('degraded');
        case 'paused':
            return trans('Horizon paused');
        case 'inactive':
            return trans('inactive');
        case 'unreachable':
            return trans('unreachable');
        case 'stale':
            return trans('not updated');
    }
}

const STATUS_ICONS: Record<App.Enums.EnvironmentStatus, Component> = {
    active: PhWarning,
    degraded: PhWarning,
    paused: PhPauseCircle,
    inactive: PhWarningOctagon,
    unreachable: PhPlugs,
    stale: PhClockCountdown,
};

export function statusIcon(status: App.Enums.EnvironmentStatus): Component {
    return STATUS_ICONS[status];
}

export function formatCount(value: number): string {
    if (value < 1000) {
        return String(value);
    }

    const [divisor, suffix] = value >= 999500 ? [1000000, 'M'] : [1000, 'k'];
    const scaled = value / divisor;
    const digits = scaled >= 10 ? 0 : 1;

    return `${new Intl.NumberFormat(getActiveLanguage(), {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
        useGrouping: false,
    }).format(scaled)}${suffix}`;
}

const DURATION_UNITS: readonly {
    size: number;
    label: (value: string) => string;
}[] = [
    { size: 86400, label: (days) => trans(':days d', { days }) },
    { size: 3600, label: (hours) => trans(':hours h', { hours }) },
    { size: 60, label: (minutes) => trans(':minutes min', { minutes }) },
    { size: 1, label: (seconds) => trans(':seconds s', { seconds }) },
];

/**
 * A duration rounded down to at most two units, each spaced from its number:
 * 45 s, 1 min 30 s, 1 h 5 min, 2 d 3 h. App\Support\Duration mirrors it.
 */
export function formatSeconds(seconds: number, parts: 1 | 2 = 2): string {
    const total = Number.isFinite(seconds)
        ? Math.max(0, Math.floor(seconds))
        : 0;
    const index = DURATION_UNITS.findIndex(({ size }) => total >= size);
    const major =
        DURATION_UNITS[index === -1 ? DURATION_UNITS.length - 1 : index];
    const minor =
        parts === 2 && index !== -1 ? DURATION_UNITS[index + 1] : undefined;
    const text = major.label(String(Math.floor(total / major.size)));

    if (minor === undefined) {
        return text;
    }

    const count = Math.floor((total % major.size) / minor.size);

    return count > 0 ? `${text} ${minor.label(String(count))}` : text;
}

/** Average job runtime: one decimal under a minute (0.4 s), since Horizon reports sub-second runtimes. */
export function formatRuntime(seconds: number): string {
    const rounded = Math.round(seconds * 10) / 10;

    if (rounded >= 60) {
        return formatSeconds(rounded);
    }

    return trans(':seconds s', {
        seconds: new Intl.NumberFormat(getActiveLanguage(), {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        }).format(rounded),
    });
}

/** Chart axes only: one unit, no space (30s, 2min, 12h, 2d). */
export function formatSecondsCompact(seconds: number): string {
    return formatSeconds(seconds, 1).replace(' ', '');
}

export function formatAgo(seconds: number): string {
    return seconds < 60
        ? trans('less than a minute ago')
        : trans(':time ago', { time: formatSeconds(seconds, 1) });
}

/** Thresholds and alert values: exact and grouped, like AlertMail::number(). */
export function formatNumber(value: number): string {
    return new Intl.NumberFormat(getActiveLanguage(), {
        maximumFractionDigits: 2,
    }).format(value);
}

export function seriesRefreshInterval(ms: number, stepSeconds: number): number {
    return Math.max(
        ms,
        Math.min(
            (stepSeconds * 1000) / SERIES_REFRESH_PER_STEP,
            SERIES_REFRESH_MAX_MS,
        ),
    );
}
