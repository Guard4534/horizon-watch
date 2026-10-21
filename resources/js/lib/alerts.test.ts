import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { isMuted, mutedText, pageCount, severityColor } from '@/lib/alerts';

const alert = (
    overrides: Partial<App.Data.Monitoring.AlertData> = {},
): App.Data.Monitoring.AlertData => ({
    id: '01J0000000000000000000000A',
    state: 'open',
    severity: 'warning',
    metric: 'queue.pending',
    threshold: 100,
    unit: 'job',
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

    it('adds the weekday when the mute ends on another day', () => {
        const muted = alert({
            mutedUntil: new Date(2026, 9, 15, 9, 5).toISOString(),
        });

        expect(mutedText(muted, 'en')).toBe('Muted until Thu 09:05');
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
