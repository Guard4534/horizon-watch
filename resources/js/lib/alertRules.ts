import {
    PhClockCountdown,
    PhDatabase,
    PhHourglassHigh,
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
    'queue.pending': PhTray,
    'queue.max_wait': PhHourglassHigh,
    'job.runtime': PhClockCountdown,
    'jobs.failed_per_hour': PhXCircle,
    'workers.missing': PhUsers,
    'redis.memory': PhDatabase,
};

// English source strings: pages pass them through $t().
const LABELS: Record<App.Enums.AlertRuleMetric, { label: string; hint: string }> = {
    'horizon.master_inactive': { label: 'Horizon inactive', hint: 'no active master supervisor for' },
    'endpoint.unreachable': { label: 'Endpoint unreachable', hint: 'timeout or HTTP error for' },
    'queue.pending': { label: 'Pending jobs', hint: 'total across all queues above' },
    'queue.max_wait': { label: 'Max wait', hint: 'oldest job waiting longer than' },
    'job.runtime': { label: 'Job runtime', hint: 'job running for more than' },
    'jobs.failed_per_hour': { label: 'Failed jobs / hour', hint: 'failed jobs in one hour above' },
    'workers.missing': { label: 'Missing workers', hint: 'active processes below' },
    'redis.memory': { label: 'Redis memory', hint: 'memory used above' },
};

export function ruleIcon(metric: App.Enums.AlertRuleMetric): Component {
    return ICONS[metric];
}

export function ruleLabel(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].label;
}

export function ruleHint(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].hint;
}

/** "5 min", "60s", "2000", "4 GB": the spacing the mockup uses for each unit. */
export function formatThreshold(threshold: number, unit: string): string {
    const value = Number.isInteger(threshold) ? String(threshold) : threshold.toFixed(1);

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

export function formatRule(metric: App.Enums.AlertRuleMetric, threshold: number, unit: string): string {
    return `${metric} > ${formatThreshold(threshold, unit)}`;
}
