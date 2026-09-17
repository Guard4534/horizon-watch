import {
    PhClockCountdown,
    PhHourglassHigh,
    PhPauseCircle,
    PhPlugs,
    PhPower,
    PhTray,
    PhUsers,
    PhXCircle,
} from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

export const DEFAULT_PENDING_THRESHOLD = 2000;

const ICONS: Record<App.Enums.AlertRuleMetric, Component> = {
    'horizon.master_inactive': PhPower,
    'endpoint.unreachable': PhPlugs,
    'horizon.paused': PhPauseCircle,
    'queue.pending': PhTray,
    'queue.max_wait': PhHourglassHigh,
    'job.runtime': PhClockCountdown,
    'jobs.failed_per_hour': PhXCircle,
    'workers.missing': PhUsers,
};

const LABELS: Record<
    App.Enums.AlertRuleMetric,
    { label: () => string; hint: () => string }
> = {
    'horizon.master_inactive': {
        label: () => trans('Horizon inactive'),
        hint: () => trans('no active master supervisor for'),
    },
    'endpoint.unreachable': {
        label: () => trans('Endpoint unreachable'),
        hint: () => trans('timeout or HTTP error for'),
    },
    'horizon.paused': {
        label: () => trans('Horizon paused'),
        hint: () => trans('Horizon or every master supervisor paused for'),
    },
    'queue.pending': {
        label: () => trans('Pending jobs'),
        hint: () => trans('total across all queues above'),
    },
    'queue.max_wait': {
        label: () => trans('Max wait'),
        hint: () => trans('oldest job waiting longer than'),
    },
    'job.runtime': {
        label: () => trans('Job runtime'),
        hint: () => trans('job running for more than'),
    },
    'jobs.failed_per_hour': {
        label: () => trans('Failed jobs / hour'),
        hint: () => trans('failed in the last hour, above'),
    },
    'workers.missing': {
        label: () => trans('Missing workers'),
        hint: () => trans('queues with waiting jobs and no worker, at least'),
    },
};

const STATE_METRICS: ReadonlySet<App.Enums.AlertRuleMetric> = new Set([
    'horizon.master_inactive',
    'endpoint.unreachable',
    'horizon.paused',
]);

export function ruleIcon(metric: App.Enums.AlertRuleMetric): Component {
    return ICONS[metric];
}

export function ruleLabel(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].label();
}

export function ruleHint(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].hint();
}

export function isStateMetric(metric: App.Enums.AlertRuleMetric): boolean {
    return STATE_METRICS.has(metric);
}

export function formatThreshold(threshold: number, unit: string): string {
    const value = Number.isInteger(threshold)
        ? String(threshold)
        : threshold.toFixed(1);

    switch (unit) {
        case 's':
            return `${value}s`;
        case 'job':
        case '':
            return value;
        default:
            return `${value} ${unit}`;
    }
}

export function formatRule(
    metric: App.Enums.AlertRuleMetric,
    threshold: number,
    unit: string,
): string {
    const comparison =
        isStateMetric(metric) || metric === 'workers.missing' ? '≥' : '>';

    return `${metric} ${comparison} ${formatThreshold(threshold, unit)}`;
}
