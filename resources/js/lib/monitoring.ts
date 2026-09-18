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
    }
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

export function waitColor(seconds: number, threshold = 60): string {
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
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m ${seconds % 60}s`;
}
