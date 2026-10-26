import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { PhCheckCircle } from '@phosphor-icons/vue';
import {
    alertIcon,
    alertTone,
    deliveryErrorLabel,
    isMuted,
    mutedText,
    pageCount,
    severityColor,
} from '@/lib/alerts';
import { ruleIcon } from '@/lib/alertRules';

const alert = (
    overrides: Partial<App.Data.Monitoring.AlertData> = {},
): App.Data.Monitoring.AlertData => ({
    id: '01J0000000000000000000000A',
    state: 'open',
    severity: 'warning',
    metric: 'queue.pending',
    threshold: 100,
    unit: 'job',
    comparator: '>',
    isStateRule: false,
    value: 250,
    valueIsLowerBound: false,
    longestJob: null,
    longestJobQueue: null,
    queuesWithoutWorkers: [],
    environmentId: null,
    applicationName: 'Billing',
    environmentName: 'production',
    environmentType: 'production',
    environmentStatus: 'degraded',
    collectionPaused: false,
    minutesAgo: 3,
    resolvedMinutesAgo: null,
    mutedUntil: null,
    mutedUntilResolved: false,
    mutedBy: null,
    mutedForMaintenance: false,
    handledBy: null,
    handledMinutesAgo: null,
    channels: [],
    canMute: true,
    canHandle: true,
    ...overrides,
});

describe('mutedText', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 14, 10, 0));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('says nothing about an alert that is not muted', () => {
        expect(isMuted(alert())).toBe(false);
        expect(mutedText(alert(), 'en')).toBeNull();
    });

    it('names who muted it until resolved', () => {
        const muted = alert({
            mutedUntilResolved: true,
            mutedBy: { name: 'Ada' },
        });

        expect(isMuted(muted)).toBe(true);
        expect(mutedText(muted, 'en')).toBe('Muted by Ada until resolved');
        expect(mutedText(alert({ mutedUntilResolved: true }), 'en')).toBe(
            'Muted until resolved',
        );
    });

    it('shows only the local time when the mute ends today', () => {
        const muted = alert({
            mutedUntil: new Date(2026, 9, 14, 14, 30).toISOString(),
            mutedBy: { name: 'Ada' },
        });

        expect(mutedText(muted, 'en')).toBe('Muted by Ada until 14:30');
        expect(mutedText({ ...muted, mutedBy: null }, 'en')).toBe(
            'Muted until 14:30',
        );
    });

    it('adds the weekday, day and month when the mute ends on another day', () => {
        const muted = alert({
            mutedUntil: new Date(2026, 9, 15, 9, 5).toISOString(),
        });

        expect(mutedText(muted, 'en')).toBe('Muted until Thu, Oct 15, 09:05');
    });

    it('credits a maintenance window over the person', () => {
        const muted = alert({
            mutedUntil: new Date(2026, 9, 14, 18, 0).toISOString(),
            mutedForMaintenance: true,
            mutedBy: { name: 'Ada' },
        });

        expect(mutedText(muted, 'en')).toBe(
            'Muted for maintenance until 18:00',
        );
    });
});

describe('pageCount', () => {
    it('always has at least one page', () => {
        expect(pageCount(0, 25)).toBe(1);
        expect(pageCount(25, 25)).toBe(1);
        expect(pageCount(26, 25)).toBe(2);
        expect(pageCount(10, 0)).toBe(10);
    });
});

describe('severityColor', () => {
    it('keeps critical red and warning amber', () => {
        expect(severityColor('critical')).toBe('var(--st-down)');
        expect(severityColor('warning')).toBe('var(--st-warn)');
    });
});

describe('alertTone and alertIcon', () => {
    it('shows a resolved alert as back to normal, with a check', () => {
        const resolved = alert({ state: 'resolved', severity: 'critical' });

        expect(alertTone(resolved)).toBe('var(--st-ok)');
        expect(alertIcon(resolved)).toBe(PhCheckCircle);
    });

    it('keeps a muted alert neutral, with the icon of its rule', () => {
        const muted = alert({
            state: 'muted',
            severity: 'critical',
            mutedUntilResolved: true,
        });

        expect(alertTone(muted)).toBe('var(--nc-neutral-500)');
        expect(alertIcon(muted)).toBe(ruleIcon('queue.pending'));
    });

    it('tones an open alert by its severity, whatever the environment says', () => {
        expect(
            alertTone(
                alert({
                    severity: 'warning',
                    environmentStatus: 'unreachable',
                }),
            ),
        ).toBe('var(--st-warn)');
        expect(
            alertTone(
                alert({ severity: 'critical', environmentStatus: 'paused' }),
            ),
        ).toBe('var(--st-down)');
        expect(
            alertTone(alert({ metric: 'horizon.paused', severity: 'warning' })),
        ).toBe('var(--st-warn)');
        expect(alertIcon(alert({ metric: 'horizon.paused' }))).toBe(
            ruleIcon('horizon.paused'),
        );
    });
});

describe('deliveryErrorLabel', () => {
    it('says what went wrong in words, never the code', () => {
        expect(deliveryErrorLabel('timeout', null)).toBe(
            'the destination did not answer in time',
        );
        expect(deliveryErrorLabel('blocked', null)).toBe(
            'the address points to a network the panel does not call',
        );
        expect(deliveryErrorLabel('http_3xx', null)).toBe(
            'redirect not followed',
        );
        expect(deliveryErrorLabel('http_4xx', null)).toBe(
            'refused by the destination',
        );
        expect(deliveryErrorLabel('http_5xx', null)).toBe(
            'error at the destination',
        );
        expect(deliveryErrorLabel('unreachable', null)).toBe(
            'destination unreachable',
        );
        expect(deliveryErrorLabel('mail', null)).toBe(
            'the email could not be sent',
        );
        expect(deliveryErrorLabel('opening_not_delivered', null)).toBe(
            'its opening never reached this destination',
        );
    });

    it('names the HTTP status the destination answered with', () => {
        expect(deliveryErrorLabel('http_4xx', 404)).toBe('HTTP 404');
    });

    it('has a sentence for every delivery error', () => {
        const codes: App.Enums.DeliveryError[] = [
            'timeout',
            'blocked',
            'http_3xx',
            'http_4xx',
            'http_5xx',
            'unreachable',
            'mail',
            'mail_unreachable',
            'mail_authentication',
            'mail_rejected',
            'secret_unreadable',
            'no_longer_due',
            'opening_not_delivered',
        ];

        for (const code of codes) {
            expect(deliveryErrorLabel(code, null)).not.toBe(code);
            expect(deliveryErrorLabel(code, null)).toContain(' ');
        }
    });
});
