import {
    PhPauseCircle,
    PhPlugs,
    PhWarning,
    PhWarningOctagon,
} from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

const STATUS_COLORS: Record<App.Enums.EnvironmentStatus, string> = {
    active: 'var(--st-ok)',
    degraded: 'var(--st-warn)',
    paused: 'var(--st-off)',
    inactive: 'var(--st-down)',
    unreachable: 'var(--st-down)',
};

export function statusColor(status: App.Enums.EnvironmentStatus): string {
    return STATUS_COLORS[status];
}

export function envColor(color: App.Enums.EnvironmentColor): string {
    return `var(--env-${color})`;
}

// Horizon's own states stay English in every language; only "unreachable" is ours.
export function statusLabel(status: App.Enums.EnvironmentStatus): string {
    return status === 'unreachable' ? trans('unreachable') : status;
}

export function isDown(status: App.Enums.EnvironmentStatus): boolean {
    return status === 'inactive' || status === 'unreachable';
}

const STATUS_ICONS: Record<App.Enums.EnvironmentStatus, Component> = {
    active: PhWarning,
    degraded: PhWarning,
    paused: PhPauseCircle,
    inactive: PhWarningOctagon,
    unreachable: PhPlugs,
};

export function statusIcon(status: App.Enums.EnvironmentStatus): Component {
    return STATUS_ICONS[status];
}

export function formatCount(value: number): string {
    if (value < 1000) {
        return String(value);
    }

    return `${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}k`;
}

export function formatWait(seconds: number): string {
    return seconds >= 60 ? `${Math.round(seconds / 60)}m` : `${seconds}s`;
}

/**
 * Amber strictly above the queue.max_wait threshold, as StatusEvaluator
 * fires; red strictly above five minutes (or the threshold, if higher).
 * The default is the rule's default, for pages that carry no thresholds.
 */
export function waitColor(seconds: number, threshold = 60): string {
    if (seconds > Math.max(300, threshold)) {
        return 'var(--st-down)';
    }

    return seconds > threshold ? 'var(--st-warn)' : 'var(--nc-neutral-400)';
}

export function pendingColor(pending: number): string {
    if (pending > 2000) {
        return 'var(--st-down)';
    }

    return pending > 1000 ? 'var(--st-warn)' : 'var(--nc-text)';
}

/**
 * How long ago something began, for readers: under a minute, minutes under
 * an hour, then whole hours. `capped` means the real start lies beyond the
 * 24-hour look-back (AlertData.sinceTruncated), so the minutes are a floor.
 */
export function formatElapsed(minutes: number, capped = false): string {
    if (capped) {
        return trans('more than 24 h ago');
    }

    if (minutes < 1) {
        return trans('less than a minute ago');
    }

    if (minutes < 60) {
        return trans(':minutes min ago', { minutes: String(minutes) });
    }

    return trans(':hours h ago', { hours: String(Math.floor(minutes / 60)) });
}

export function formatDuration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m ${seconds % 60}s`;
}
