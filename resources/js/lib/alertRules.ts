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

// Each entry calls trans() with a literal, so TranslationsTest sees every
// key. The thunks run when a component renders, which is what keeps them
// reactive to a language switch (trans() reads laravel-vue-i18n's reactive
// messages, exactly like $t): never call them at module load.
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
    // StatusEvaluator divides Horizon's failed count by the window Horizon
    // counts it over (often a week), not by the last hour.
    'jobs.failed_per_hour': {
        label: () => trans('Failed jobs / hour'),
        hint: () =>
            trans(
                'hourly average over the window Horizon counts failures in, above',
            ),
    },
    // StatusEvaluator counts queues with jobs waiting and no process, and
    // fires at the threshold itself (>=), not below it.
    'workers.missing': {
        label: () => trans('Missing workers'),
        hint: () => trans('queues with waiting jobs and no worker, at least'),
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

/** Translated: callers must not wrap it in $t(). */
export function ruleLabel(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].label();
}

/** Translated: callers must not wrap it in $t(). */
export function ruleHint(metric: App.Enums.AlertRuleMetric): string {
    return LABELS[metric].hint();
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
