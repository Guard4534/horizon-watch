import { trans } from 'laravel-vue-i18n';
import type { EnvironmentColor, EnvironmentStatus } from '@/types/monitoring';

const STATUS_COLORS: Record<EnvironmentStatus, string> = {
    active: 'var(--st-ok)',
    degraded: 'var(--st-warn)',
    paused: 'var(--st-off)',
    inactive: 'var(--st-down)',
    unreachable: 'var(--st-down)',
};

export function statusColor(status: EnvironmentStatus): string {
    return STATUS_COLORS[status];
}

export function envColor(color: EnvironmentColor): string {
    return `var(--env-${color})`;
}

// Horizon's own states stay English in every language; only "unreachable" is ours.
export function statusLabel(status: EnvironmentStatus): string {
    return status === 'unreachable' ? trans('unreachable') : status;
}

export function isDown(status: EnvironmentStatus): boolean {
    return status === 'inactive' || status === 'unreachable';
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

export function waitColor(seconds: number): string {
    if (seconds >= 300) {
        return 'var(--st-down)';
    }

    return seconds >= 60 ? 'var(--st-warn)' : 'var(--nc-neutral-400)';
}

export function pendingColor(pending: number): string {
    if (pending > 2000) {
        return 'var(--st-down)';
    }

    return pending > 1000 ? 'var(--st-warn)' : 'var(--nc-text)';
}

export function formatMinutesAgo(minutes: number): string {
    return trans(':minutes min ago', { minutes: String(minutes) });
}

export function formatDuration(seconds: number): string {
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}
