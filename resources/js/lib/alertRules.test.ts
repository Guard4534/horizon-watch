import { describe, expect, it } from 'vite-plus/test';
import {
    formatCondition,
    formatRule,
    formatRuleFor,
    formatThreshold,
    overriddenFieldCount,
    ruleFields,
    ruleValues,
} from '@/lib/alertRules';

const rule = (
    overrides: Partial<App.Data.Monitoring.AlertRuleData> = {},
): App.Data.Monitoring.AlertRuleData => ({
    metric: 'queue.pending',
    unit: 'job',
    threshold: 1000.4,
    severity: 'warning',
    notifyByEmail: true,
    enabled: true,
    forMinutes: null,
    overrideThreshold: null,
    overrideSeverity: null,
    overrideNotifyByEmail: null,
    overrideEnabled: null,
    overrideForMinutes: null,
    minimum: 1,
    maximum: 1000000,
    maximumForMinutes: 1440,
    origin: 'organization',
    ...overrides,
});

describe('formatRule', () => {
    it.each<[App.Enums.AlertRuleMetric, number, string, string]>([
        ['queue.pending', 100, 'job', 'Pending jobs > 100'],
        ['queue.max_wait', 90, 's', 'Max wait > 90 s'],
        ['job.runtime', 10, 'min', 'Job runtime > 10 min'],
        ['jobs.failed_per_hour', 2.5, '', 'Failed jobs / hour > 2.5'],
        ['workers.missing', 1, '', 'Missing workers ≥ 1'],
        ['horizon.master_inactive', 5, 'min', 'Horizon inactive ≥ 5 min'],
        ['endpoint.unreachable', 3, 'min', 'Endpoint unreachable ≥ 3 min'],
        ['horizon.paused', 15, 'min', 'Horizon paused ≥ 15 min'],
    ])(
        'writes %s with its readable name',
        (metric, threshold, unit, expected) => {
            expect(formatRule(metric, threshold, unit)).toBe(expected);
        },
    );
});

describe('formatCondition', () => {
    it('describes the threshold in words, without the rule id', () => {
        expect(formatCondition('horizon.master_inactive', 2, 'min')).toBe(
            'no active master supervisor for 2 min',
        );
        expect(formatCondition('queue.pending', 2000, 'job')).toBe(
            'total across all queues above 2,000',
        );
    });
});

describe('formatThreshold', () => {
    it('writes the exact number, grouped, with the unit it was set in', () => {
        expect(formatThreshold(2.25, '')).toBe('2.25');
        expect(formatThreshold(30, 's')).toBe('30 s');
        expect(formatThreshold(90, 's')).toBe('90 s');
        expect(formatThreshold(5, 'min')).toBe('5 min');
        expect(formatThreshold(2000, 'job')).toBe('2,000');
        expect(formatThreshold(7, 'MB')).toBe('7 MB');
    });
});

describe('formatRuleFor', () => {
    it('writes how long a breach must last as a duration', () => {
        expect(formatRuleFor(5)).toBe('for 5 min');
        expect(formatRuleFor(60)).toBe('for 1 h');
        expect(formatRuleFor(90)).toBe('for 1 h 30 min');
    });
});

describe('ruleFields', () => {
    it('sends every value, rounded, for an organization rule', () => {
        expect(ruleFields(rule({ forMinutes: 5 }), true)).toEqual({
            metric: 'queue.pending',
            threshold: 1000,
            severity: 'warning',
            notifyByEmail: true,
            enabled: true,
            forMinutes: 5,
        });
    });

    it('sends only the overrides for an environment rule', () => {
        expect(
            ruleFields(
                rule({ overrideThreshold: 49.6, overrideEnabled: false }),
                false,
            ),
        ).toEqual({
            metric: 'queue.pending',
            threshold: 50,
            severity: null,
            notifyByEmail: null,
            enabled: false,
            forMinutes: null,
        });
    });

    it('inherits everything when an environment rule has no override', () => {
        const fields = ruleFields(rule(), false);

        expect(fields.threshold).toBeNull();
        expect(overriddenFieldCount(fields)).toBe(0);
    });
});

describe('overriddenFieldCount', () => {
    it('counts the fields that are set, false and zero included', () => {
        expect(
            overriddenFieldCount({
                metric: 'queue.pending',
                threshold: 0,
                severity: null,
                notifyByEmail: false,
                enabled: null,
                forMinutes: 10,
            }),
        ).toBe(3);
    });
});

describe('ruleValues', () => {
    it('reads the effective values with a whole threshold', () => {
        expect(ruleValues(rule({ severity: 'critical' }))).toEqual({
            threshold: 1000,
            severity: 'critical',
            notifyByEmail: true,
            enabled: true,
            forMinutes: null,
        });
    });
});
