import { describe, expect, it } from 'vite-plus/test';
import {
    axisTicks,
    formatAxisCount,
    formatBucketTime,
    formatClock,
    niceCountMax,
    niceSecondsMax,
    rangeLabel,
    spansDays,
    timeTicks,
} from '@/lib/timeSeries';

const local = (
    month: number,
    day: number,
    hour = 0,
    minute = 0,
    year = 2026,
): number => new Date(year, month - 1, day, hour, minute).getTime();

const labels = (from: number, until: number, maxLabels: number) =>
    timeTicks(from, until, maxLabels, 'en').map((tick) => tick.label);

describe('niceCountMax', () => {
    it.each([
        [0, 1],
        [1, 1],
        [2, 2],
        [3, 4],
        [5, 6],
        [7, 8],
        [9, 10],
        [11, 20],
        [150, 200],
        [801, 1000],
        [4001, 6000],
    ])('rounds %d up to %d', (value, expected) => {
        expect(niceCountMax(value)).toBe(expected);
    });
});

describe('niceSecondsMax', () => {
    it.each([
        [0, 1],
        [3, 4],
        [45, 60],
        [61, 120],
        [3599, 3600],
        [86400, 86400],
        [86401, 172800],
        [200000, 345600],
    ])('rounds %d seconds up to %d', (value, expected) => {
        expect(niceSecondsMax(value)).toBe(expected);
    });
});

describe('axisTicks', () => {
    it('adds a middle tick only when it is a whole number', () => {
        expect(axisTicks(8)).toEqual([0, 4, 8]);
        expect(axisTicks(1)).toEqual([0, 1]);
        expect(axisTicks(1800)).toEqual([0, 900, 1800]);
    });
});

describe('formatAxisCount', () => {
    it('drops a trailing zero decimal from compact counts', () => {
        expect(formatAxisCount(400)).toBe('400');
        expect(formatAxisCount(1000)).toBe('1k');
        expect(formatAxisCount(1500)).toBe('1.5k');
        expect(formatAxisCount(2000000)).toBe('2M');
    });
});

describe('spansDays', () => {
    it('switches to day labels only beyond two days', () => {
        expect(spansDays(2 * 86400)).toBe(false);
        expect(spansDays(2 * 86400 + 1)).toBe(true);
    });
});

describe('timeTicks', () => {
    it('runs in a time zone with daylight saving time', () => {
        expect(new Date(local(1, 15)).getTimezoneOffset()).toBe(-60);
        expect(new Date(local(7, 15)).getTimezoneOffset()).toBe(-120);
    });

    it('picks the finest step that fits the labels on a three hour range', () => {
        const ticks = timeTicks(
            local(10, 14, 9, 7),
            local(10, 14, 12, 7),
            6,
            'en',
        );

        expect(ticks.map((tick) => tick.label)).toEqual([
            '09:30',
            '10:00',
            '10:30',
            '11:00',
            '11:30',
            '12:00',
        ]);
        expect(ticks[0].at).toBe(local(10, 14, 9, 30));
    });

    it('keeps ticks on local clock hours across midnight', () => {
        expect(labels(local(10, 14, 12, 7), local(10, 15, 12, 7), 6)).toEqual([
            '16:00',
            '20:00',
            '00:00',
            '04:00',
            '08:00',
            '12:00',
        ]);
    });

    it('labels local midnights with the day beyond two days', () => {
        const ticks = timeTicks(local(10, 8, 12), local(10, 15, 12), 7, 'en');

        expect(ticks.map((tick) => tick.label)).toEqual([
            'Fri 9',
            'Sat 10',
            'Sun 11',
            'Mon 12',
            'Tue 13',
            'Wed 14',
            'Thu 15',
        ]);
        expect(ticks.every((tick) => new Date(tick.at).getHours() === 0)).toBe(
            true,
        );
    });

    it('widens the day step when every day does not fit', () => {
        expect(labels(local(10, 8, 12), local(10, 15, 12), 4)).toEqual([
            'Sat 10',
            'Mon 12',
            'Wed 14',
        ]);
    });

    it('skips the hour lost when the clocks go forward', () => {
        expect(labels(local(3, 29, 0), local(3, 29, 6), 10)).toEqual([
            '00:00',
            '01:00',
            '03:00',
            '04:00',
            '05:00',
            '06:00',
        ]);
    });

    it('never repeats or reorders ticks on the short day', () => {
        const ticks = timeTicks(local(3, 29, 0), local(3, 29, 6), 30, 'en');

        expect(ticks.map((tick) => tick.label)).toEqual([
            '00:00',
            '00:15',
            '00:30',
            '00:45',
            '01:00',
            '01:15',
            '01:30',
            '01:45',
            '03:00',
            '03:15',
            '03:30',
            '03:45',
            '04:00',
            '04:15',
            '04:30',
            '04:45',
            '05:00',
            '05:15',
            '05:30',
            '05:45',
            '06:00',
        ]);
    });

    it('keeps day ticks on local midnight across the autumn change', () => {
        const ticks = timeTicks(local(10, 22, 12), local(10, 29, 12), 7, 'en');

        expect(ticks.map((tick) => tick.label)).toEqual([
            'Fri 23',
            'Sat 24',
            'Sun 25',
            'Mon 26',
            'Tue 27',
            'Wed 28',
            'Thu 29',
        ]);
        expect(ticks[3].at - ticks[2].at).toBe(25 * 3600 * 1000);
        expect(ticks.every((tick) => new Date(tick.at).getHours() === 0)).toBe(
            true,
        );
    });

    it('formats the labels in the given locale', () => {
        expect(
            timeTicks(local(10, 8, 12), local(10, 10, 12, 1), 7, 'it').map(
                (tick) => tick.label,
            ),
        ).toEqual(['ven 9', 'sab 10']);
    });
});

describe('formatBucketTime', () => {
    it('adds the day only when asked to', () => {
        const at = new Date(local(10, 14, 9, 7));

        expect(formatBucketTime(at, 'en', false)).toBe('09:07');
        expect(formatBucketTime(at, 'en', true)).toBe('Wed 14 09:07');
        expect(formatClock(new Date(local(10, 14, 0, 5)), 'en')).toBe('00:05');
    });
});

describe('rangeLabel', () => {
    it('names every series range', () => {
        expect(rangeLabel('3h')).toBe('last 3 hours');
        expect(rangeLabel('24h')).toBe('last 24 hours');
        expect(rangeLabel('7d')).toBe('last 7 days');
    });
});
