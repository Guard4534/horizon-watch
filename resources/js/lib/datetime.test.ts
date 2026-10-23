import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    browserTimeZone,
    formatInstant,
    quietHoursSummary,
} from '@/lib/datetime';

describe('formatInstant', () => {
    const now = new Date(2026, 9, 14, 10, 0);

    it('shows only the time for today', () => {
        expect(
            formatInstant(
                new Date(2026, 9, 14, 22, 15).toISOString(),
                'en',
                now,
            ),
        ).toBe('22:15');
    });

    it('adds the weekday, day and month on another day', () => {
        expect(
            formatInstant(new Date(2026, 9, 16, 6, 0).toISOString(), 'en', now),
        ).toBe('Fri, Oct 16, 06:00');
        expect(
            formatInstant(
                new Date(2026, 9, 13, 23, 59).toISOString(),
                'en',
                now,
            ),
        ).toBe('Tue, Oct 13, 23:59');
    });

    describe('without an explicit now', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            vi.setSystemTime(now);
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('compares with the current day', () => {
            expect(
                formatInstant(new Date(2026, 9, 14, 9, 5).toISOString(), 'en'),
            ).toBe('09:05');
        });
    });
});

describe('quietHoursSummary', () => {
    it('writes the window with its time zone', () => {
        expect(quietHoursSummary('22:00', '07:00', 'Europe/Rome')).toBe(
            '22:00 → 07:00 · Europe/Rome',
        );
    });

    it('says none without both ends', () => {
        expect(quietHoursSummary(null, null, 'UTC')).toBe('None');
        expect(quietHoursSummary('22:00', null, 'UTC')).toBe('None');
    });
});

describe('browserTimeZone', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('names the zone the browser formats times in', () => {
        expect(browserTimeZone()).toBe('Europe/Rome');
    });

    it('falls back to UTC when the browser names no zone', () => {
        const real = new Intl.DateTimeFormat().resolvedOptions();

        vi.spyOn(
            Intl.DateTimeFormat.prototype,
            'resolvedOptions',
        ).mockReturnValue({ ...real, timeZone: '' });

        expect(browserTimeZone()).toBe('UTC');
    });
});
