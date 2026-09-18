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

export function thresholdOf(
    environment: App.Data.Monitoring.EnvironmentData,
    metric: App.Enums.AlertRuleMetric,
): number | null {
    return environment.thresholds[metric] ?? null;
}

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

export function unitLabel(unit: string): string {
    switch (unit) {
        case 'min':
            return trans('min');
        case 's':
            return trans('s');
        case 'job':
            return trans('jobs');
        default:
            return unit;
    }
}

export function formatThreshold(threshold: number, unit: string): string {
    const value = Number.isInteger(threshold)
        ? String(threshold)
        : threshold.toFixed(1);

    switch (unit) {
        case 's':
            return `${value}${unitLabel(unit)}`;
        case 'job':
        case '':
            return value;
        default:
            return `${value} ${unitLabel(unit)}`;
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

export type RuleField = 'threshold' | 'severity' | 'notifyByEmail' | 'enabled';

export const RULE_FIELDS: readonly RuleField[] = [
    'threshold',
    'severity',
    'notifyByEmail',
    'enabled',
];

export function ruleFields(
    rule: App.Data.Monitoring.AlertRuleData,
    organizationScope: boolean,
): App.Data.Alerts.AlertRuleInputData {
    if (organizationScope) {
        return {
            metric: rule.metric,
            threshold: Math.round(rule.threshold),
            severity: rule.severity,
            notifyByEmail: rule.notifyByEmail,
            enabled: rule.enabled,
        };
    }

    return {
        metric: rule.metric,
        threshold:
            rule.overrideThreshold === null
                ? null
                : Math.round(rule.overrideThreshold),
        severity: rule.overrideSeverity,
        notifyByEmail: rule.overrideNotifyByEmail,
        enabled: rule.overrideEnabled,
    };
}

export type RuleValues = {
    threshold: number;
    severity: App.Enums.AlertSeverity;
    notifyByEmail: boolean;
    enabled: boolean;
};

export function ruleValues(
    rule: App.Data.Monitoring.AlertRuleData,
): RuleValues {
    return {
        threshold: Math.round(rule.threshold),
        severity: rule.severity,
        notifyByEmail: rule.notifyByEmail,
        enabled: rule.enabled,
    };
}

export function overriddenFieldCount(
    fields: App.Data.Alerts.AlertRuleInputData,
): number {
    return RULE_FIELDS.filter((field) => fields[field] !== null).length;
}
