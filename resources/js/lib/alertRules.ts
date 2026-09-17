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
import type { Component } from 'vue';

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

// English source strings: pages pass them through $t(). TranslationsTest
// cannot see keys kept in a table, so lang/it.json is checked by hand.
const LABELS: Record<
    App.Enums.AlertRuleMetric,
    { label: string; hint: string }
> = {
    'horizon.master_inactive': {
        label: 'Horizon inactive',
        hint: 'no active master supervisor for',
    },
    'endpoint.unreachable': {
        label: 'Endpoint unreachable',
        hint: 'timeout or HTTP error for',
    },
    'horizon.paused': {
        label: 'Horizon paused',
        hint: 'Horizon or every master supervisor paused for',
    },
    'queue.pending': {
        label: 'Pending jobs',
        hint: 'total across all queues above',
    },
    'queue.max_wait': {
        label: 'Max wait',
        hint: 'oldest job waiting longer than',
    },
    'job.runtime': { label: 'Job runtime', hint: 'job running for more than' },
    // StatusEvaluator divides Horizon's failed count by the window Horizon
    // counts it over (often a week), not by the last hour.
    'jobs.failed_per_hour': {
        label: 'Failed jobs / hour',
        hint: 'hourly average over the window Horizon counts failures in, above',
    },
    // StatusEvaluator counts queues with jobs waiting and no process, and
    // fires at the threshold itself (>=), not below it.
    'workers.missing': {
        label: 'Missing workers',
        hint: 'queues with waiting jobs and no worker, at least',
    },
};

// These open an anomaly at the first reading in that state: the minutes of
// their rule are not applied to the anomaly (see StoredReadings).
const STATE_METRICS: ReadonlySet<App.Enums.AlertRuleMetric> = new Set([
    'horizon.master_inactive',
    'endpoint.unreachable',
    'horizon.paused',
]);

export function ruleIcon(metric: App.Enums.AlertRuleMetric): Component {
    return ICONS[metric];
}

export function ruleLabel(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].label;
}

export function ruleHint(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].hint;
}

export function isStateMetric(metric: App.Enums.AlertRuleMetric): boolean {
    return STATE_METRICS.has(metric);
}

/** "5 min", "60s", "2000": the spacing the mockup uses for each unit. */
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

/**
 * The rule as it is evaluated today: a state rule is the state itself (its
 * minutes are not applied yet), missing workers fire at the threshold.
 */
export function formatRule(
    metric: App.Enums.AlertRuleMetric,
    threshold: number,
    unit: string,
): string {
    if (isStateMetric(metric)) {
        return metric;
    }

    const comparison = metric === 'workers.missing' ? '≥' : '>';

    return `${metric} ${comparison} ${formatThreshold(threshold, unit)}`;
}
