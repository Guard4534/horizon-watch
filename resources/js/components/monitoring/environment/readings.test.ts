import { describe, expect, it } from 'vite-plus/test';
import {
    failedTone,
    formatAge,
    hasMeasurement,
    horizonStatusTone,
    needsAttention,
    pendingTone,
    readingProblemLabel,
    secondsSince,
    statusText,
    statusTone,
    worstTone,
} from '@/components/monitoring/environment/readings';

describe('needsAttention', () => {
    it.each<[App.Enums.EnvironmentStatus | null, boolean]>([
        ['active', false],
        [null, false],
        ['degraded', true],
        ['paused', true],
        ['inactive', true],
        ['unreachable', true],
        ['stale', true],
    ])('%s needs attention: %s', (status, expected) => {
        expect(needsAttention({ status })).toBe(expected);
    });
});

describe('worstTone', () => {
    it('takes the colour of the worst status', () => {
        expect(
            worstTone([
                { status: 'active' },
                { status: 'stale' },
                { status: null },
            ]),
        ).toBe('var(--st-warn)');
        expect(
            worstTone([{ status: 'degraded' }, { status: 'unreachable' }]),
        ).toBe('var(--st-down)');
        expect(worstTone([{ status: 'active' }])).toBe('var(--st-ok)');
    });

    it('has no tone without a status', () => {
        expect(worstTone([])).toBeNull();
        expect(worstTone([{ status: null }])).toBeNull();
    });
});

describe('statusText', () => {
    it('explains an environment without a status', () => {
        const environment = {
            status: null,
            watched: true,
            pollingEnabled: true,
        };

        expect(statusText(environment)).toBe('waiting for the first reading');
        expect(statusText({ ...environment, pollingEnabled: false })).toBe(
            'Collection paused',
        );
        expect(statusText({ ...environment, watched: false })).toBe(
            'Not on your wall',
        );
        expect(statusText({ ...environment, status: 'stale' })).toBe(
            'not updated',
        );
        expect(statusTone(environment)).toBe('var(--nc-neutral-500)');
    });
});

describe('hasMeasurement', () => {
    const reading = {
        status: 'active' as App.Enums.EnvironmentStatus | null,
        lastReadingAt: '2026-10-14T10:00:00Z' as string | null,
        readingError: null,
    };

    it('needs a successful reading', () => {
        expect(hasMeasurement(reading)).toBe(true);
        expect(hasMeasurement({ ...reading, status: 'stale' })).toBe(true);
        expect(hasMeasurement({ ...reading, status: 'unreachable' })).toBe(
            false,
        );
        expect(hasMeasurement({ ...reading, lastReadingAt: null })).toBe(false);
        expect(hasMeasurement({ ...reading, status: null })).toBe(false);
    });
});

describe('formatAge', () => {
    it.each([
        [-5, '0 s'],
        [59.9, '59 s'],
        [60, '1 min'],
        [3599, '59 min'],
        [3600, '1 h'],
        [172799, '47 h'],
        [172800, '2 d'],
    ])('formats %d seconds as %s', (seconds, expected) => {
        expect(formatAge(seconds)).toBe(expected);
    });

    it('never counts a reading from the future', () => {
        const now = Date.parse('2026-10-14T10:00:00Z');

        expect(secondsSince('2026-10-14T09:58:30Z', now)).toBe(90);
        expect(secondsSince('2026-10-14T10:00:30Z', now)).toBe(0);
    });
});

describe('tones', () => {
    it('only colours counts above the threshold', () => {
        expect(pendingTone(100, 100)).toBe('var(--nc-text)');
        expect(pendingTone(101, 100)).toBe('var(--st-down)');
        expect(failedTone(5, null)).toBeUndefined();
        expect(failedTone(6, 5)).toBe('var(--st-warn)');
    });

    it('greys out the Horizon status of a failed reading', () => {
        expect(
            horizonStatusTone({ horizonStatus: 'running', readingError: null }),
        ).toBe('var(--st-ok)');
        expect(
            horizonStatusTone({
                horizonStatus: 'running',
                readingError: 'unreachable',
            }),
        ).toBe('var(--nc-neutral-500)');
    });
});

describe('readingProblemLabel', () => {
    it('names the redirect target when there is one', () => {
        expect(
            readingProblemLabel({
                cause: 'redirect',
                status: 302,
                location: 'https://example.com/login',
            }),
        ).toBe('Redirected to https://example.com/login');
        expect(
            readingProblemLabel({
                cause: 'http_status',
                status: null,
                location: null,
            }),
        ).toBe('HTTP —');
    });

    it('sends a 404 back to the Horizon URL, the only address there is', () => {
        expect(
            readingProblemLabel({
                cause: 'not_found',
                status: 404,
                location: null,
            }),
        ).toBe('Nothing at this path (HTTP 404): check the Horizon URL');
    });
});
