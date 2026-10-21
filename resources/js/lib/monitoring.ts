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
            return trans('paused');
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

function compact(
    major: number,
    majorUnit: string,
    minor: number,
    minorUnit: string,
): string {
    return minor > 0
        ? `${major}${majorUnit} ${minor}${minorUnit}`
        : `${major}${majorUnit}`;
}

export function formatWait(seconds: number): string {
    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 60
        ? `${minutes}m`
        : compact(Math.floor(minutes / 60), 'h', minutes % 60, 'm');
}

export function exceeds(value: number, threshold: number | null): boolean {
    return threshold !== null && value > threshold;
}

export function waitColor(seconds: number, threshold: number | null): string {
    if (threshold === null) {
        return 'var(--nc-neutral-400)';
    }

    if (seconds > Math.max(300, threshold)) {
        return 'var(--st-down)';
    }

    return seconds > threshold ? 'var(--st-warn)' : 'var(--nc-neutral-400)';
}

export function formatElapsed(minutes: number): string {
    if (minutes < 1) {
        return trans('less than a minute ago');
    }

    if (minutes < 60) {
        return trans(':minutes min ago', { minutes: String(minutes) });
    }

    if (minutes < 1440) {
        return trans(':hours h ago', {
            hours: String(Math.floor(minutes / 60)),
        });
    }

    return trans(':days d ago', { days: String(Math.floor(minutes / 1440)) });
}

export function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return compact(Math.floor(seconds / 60), 'm', seconds % 60, 's');
    }

    return compact(
        Math.floor(seconds / 3600),
        'h',
        Math.floor((seconds % 3600) / 60),
        'm',
    );
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
