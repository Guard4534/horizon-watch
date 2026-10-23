import { describe, expect, it } from 'vite-plus/test';
import {
    displayState,
    failedLastHourText,
    hasMeasurement,
    horizonStatusTone,
    isHealthy,
    needsAttention,
    readingErrorLabel,
    readingProblemLabel,
    secondsSince,
    statusText,
    statusTone,
    thresholdTone,
} from '@/components/monitoring/environment/readings';

type State = Parameters<typeof displayState>[0];

const read: State = {
    status: 'active',
    watched: true,
    pollingEnabled: true,
    readingError: null,
    stale: false,
};

describe('displayState', () => {
    it.each<[string, Partial<State>, string]>([
        [
            'not visible beats everything',
            {
                watched: false,
                pollingEnabled: false,
                readingError: 'unreachable',
            },
            'hidden',
        ],
        [
            'a paused collection beats a reading error',
            {
                pollingEnabled: false,
                status: 'unreachable',
                readingError: 'unauthorized',
            },
            'collection_off',
        ],
        [
            'a reading error beats stale',
            {
                status: 'unreachable',
                readingError: 'unauthorized',
                stale: true,
            },
            'reading_error',
        ],
        [
            'stale beats the stored status',
            { status: 'stale', stale: true },
            'stale',
        ],
        [
            'a stale environment last known down stays down, as the server says',
            { status: 'inactive', stale: true },
            'inactive',
        ],
        ['no reading yet', { status: null }, 'waiting'],
        ['otherwise the Horizon status', { status: 'degraded' }, 'degraded'],
    ])('%s', (_, overrides, expected) => {
        expect(displayState({ ...read, ...overrides })).toBe(expected);
    });
});

describe('one wording and one tone per state', () => {
    it.each<[Partial<State>, string, string]>([
        [
            { watched: false, status: null },
            'Outside your visibility',
            'var(--nc-neutral-500)',
        ],
        [
            { pollingEnabled: false, status: 'inactive' },
            'Collection paused',
            'var(--nc-neutral-500)',
        ],
        [
            { status: 'unreachable', readingError: 'not_horizon' },
            'unreachable',
            'var(--st-down)',
        ],
        [{ status: 'stale', stale: true }, 'not updated', 'var(--st-warn)'],
        [{ status: null }, 'No reading yet', 'var(--nc-neutral-500)'],
        [{ status: 'paused' }, 'Horizon paused', 'var(--st-off)'],
        [{ status: 'degraded' }, 'degraded', 'var(--st-warn)'],
        [{ status: 'active' }, 'active', 'var(--st-ok)'],
    ])('%o reads %s', (overrides, text, tone) => {
        const environment = { ...read, ...overrides };

        expect(statusText(environment)).toBe(text);
        expect(statusTone(environment)).toBe(tone);
    });
});

describe('healthy and needs attention', () => {
    it.each<[Partial<State>, boolean, boolean]>([
        [{}, true, false],
        [{ status: 'degraded' }, false, true],
        [{ status: 'paused' }, false, true],
        [{ status: 'inactive' }, false, true],
        [{ status: 'unreachable', readingError: 'unreachable' }, false, true],
        [{ status: 'stale', stale: true }, false, true],
        [{ status: null }, false, false],
        [{ pollingEnabled: false, status: 'inactive' }, false, false],
        [{ pollingEnabled: false }, false, false],
        [{ watched: false, status: null }, false, false],
    ])(
        '%o: healthy %s, needs attention %s',
        (overrides, healthy, attention) => {
            const environment = { ...read, ...overrides };

            expect(isHealthy(environment)).toBe(healthy);
            expect(needsAttention(environment)).toBe(attention);
        },
    );
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

describe('secondsSince', () => {
    it('never counts a reading from the future', () => {
        const now = Date.parse('2026-10-14T10:00:00Z');

        expect(secondsSince('2026-10-14T09:58:30Z', now)).toBe(90);
        expect(secondsSince('2026-10-14T10:00:30Z', now)).toBe(0);
    });
});

describe('thresholdTone', () => {
    const environment = {
        pollingEnabled: true,
        thresholds: {
            'queue.pending': { value: 100, severity: 'warning' as const },
            'queue.max_wait': { value: 60, severity: 'critical' as const },
        },
    };

    it('colours a figure only past its own threshold, with the rule severity', () => {
        expect(
            thresholdTone(environment, 'queue.pending', 100),
        ).toBeUndefined();
        expect(thresholdTone(environment, 'queue.pending', 101)).toBe(
            'var(--st-warn)',
        );
        expect(thresholdTone(environment, 'queue.max_wait', 61)).toBe(
            'var(--st-down)',
        );
        expect(
            thresholdTone(environment, 'jobs.failed_per_hour', 5000),
        ).toBeUndefined();
    });

    it('leaves the last reading of a paused collection calm', () => {
        expect(
            thresholdTone(
                { ...environment, pollingEnabled: false },
                'queue.max_wait',
                900,
            ),
        ).toBeUndefined();
    });
});

describe('failedLastHourText', () => {
    it('says at least when the last hour is capped', () => {
        expect(
            failedLastHourText({
                failedLastHour: 42,
                failedLastHourCapped: false,
            }),
        ).toBe('42');
        expect(
            failedLastHourText({
                failedLastHour: 1250,
                failedLastHourCapped: false,
            }),
        ).toBe('1.3k');
        expect(
            failedLastHourText({
                failedLastHour: 50,
                failedLastHourCapped: true,
            }),
        ).toBe('at least 50');
    });
});

describe('horizonStatusTone', () => {
    const tone = (
        horizonStatus: App.Enums.HorizonStatus | null,
        readingError: App.Enums.ReadingError | null = null,
        pollingEnabled = true,
    ) => horizonStatusTone({ horizonStatus, readingError, pollingEnabled });

    it('uses the status colours, and greys out a failed reading', () => {
        expect(tone('running')).toBe('var(--st-ok)');
        expect(tone('paused')).toBe('var(--st-off)');
        expect(tone('inactive')).toBe('var(--st-down)');
        expect(tone(null)).toBe('var(--nc-neutral-500)');
        expect(tone('running', 'unreachable')).toBe('var(--nc-neutral-500)');
    });

    it('greys out the last reading of a paused collection', () => {
        expect(tone('inactive', null, false)).toBe('var(--nc-neutral-500)');
    });
});

describe('readingErrorLabel', () => {
    it.each<[App.Enums.ReadingError, string]>([
        ['unreachable', 'Horizon does not answer'],
        ['unauthorized', 'Horizon refused the credentials'],
        ['not_horizon', 'The address does not answer like Horizon'],
        ['blocked', 'This address is not allowed'],
        [
            'password_unreadable',
            'The stored password cannot be decrypted: enter it again',
        ],
    ])('labels %s', (error, expected) => {
        expect(readingErrorLabel(error)).toBe(expected);
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
