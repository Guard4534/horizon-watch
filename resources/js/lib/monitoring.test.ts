import { describe, expect, it } from 'vite-plus/test';
import {
    envTypeColor,
    formatAgo,
    formatCount,
    formatNumber,
    formatRuntime,
    formatSeconds,
    formatSecondsCompact,
    seriesRefreshInterval,
    statusColor,
    statusLabel,
} from '@/lib/monitoring';

describe('formatCount', () => {
    it.each([
        [0, '0'],
        [999, '999'],
        [1000, '1.0k'],
        [1250, '1.3k'],
        [9949, '9.9k'],
        [12500, '13k'],
        [999499, '999k'],
        [999500, '1.0M'],
        [1250000, '1.3M'],
        [12000000, '12M'],
    ])('formats %d as %s', (value, expected) => {
        expect(formatCount(value)).toBe(expected);
    });
});

describe('formatSeconds', () => {
    it.each([
        [0, '0 s'],
        [-5, '0 s'],
        [45, '45 s'],
        [59.9, '59 s'],
        [60, '1 min'],
        [90, '1 min 30 s'],
        [3599, '59 min 59 s'],
        [3600, '1 h'],
        [3959, '1 h 5 min'],
        [86399, '23 h 59 min'],
        [86400, '1 d'],
        [183600, '2 d 3 h'],
        [90061, '1 d 1 h'],
        [Number.NaN, '0 s'],
    ])('formats %d seconds as %s', (seconds, expected) => {
        expect(formatSeconds(seconds)).toBe(expected);
    });

    it('can keep only the largest unit', () => {
        expect(formatSeconds(90, 1)).toBe('1 min');
        expect(formatSeconds(3959, 1)).toBe('1 h');
        expect(formatSeconds(183600, 1)).toBe('2 d');
    });
});

describe('formatSecondsCompact', () => {
    it.each([
        [30, '30s'],
        [120, '2min'],
        [3600, '1h'],
        [5400, '1h'],
        [43200, '12h'],
        [172800, '2d'],
    ])('writes the %d s axis tick as %s', (seconds, expected) => {
        expect(formatSecondsCompact(seconds)).toBe(expected);
    });
});

describe('formatAgo', () => {
    it.each([
        [0, 'less than a minute ago'],
        [59, 'less than a minute ago'],
        [300, '5 min ago'],
        [7500, '2 h ago'],
        [180000, '2 d ago'],
    ])('writes %d seconds as %s', (seconds, expected) => {
        expect(formatAgo(seconds)).toBe(expected);
    });

    it('reads a minute count as it always did', () => {
        expect(formatAgo(125 * 60)).toBe('2 h ago');
        expect(formatAgo(3000 * 60)).toBe('2 d ago');
    });
});

describe('formatRuntime', () => {
    it.each([
        [0.4, '0.4 s'],
        [0.05, '0.1 s'],
        [12.25, '12.3 s'],
        [59.94, '59.9 s'],
        [59.96, '1 min'],
        [75, '1 min 15 s'],
    ])('writes %d seconds as %s', (seconds, expected) => {
        expect(formatRuntime(seconds)).toBe(expected);
    });
});

describe('formatNumber', () => {
    it.each([
        [2000, '2,000'],
        [1250000, '1,250,000'],
        [2.25, '2.25'],
        [2.256, '2.26'],
        [50, '50'],
    ])('writes %d exactly, grouped, as %s', (value, expected) => {
        expect(formatNumber(value)).toBe(expected);
    });
});

describe('envTypeColor', () => {
    it('points at the identity token of the type', () => {
        expect(envTypeColor('production')).toBe('var(--env-production)');
        expect(envTypeColor('development')).toBe('var(--env-development)');
    });
});

describe('statuses', () => {
    it('labels a stale environment as not updated', () => {
        expect(statusLabel('stale')).toBe('not updated');
        expect(statusLabel('unreachable')).toBe('unreachable');
        expect(statusLabel('paused')).toBe('Horizon paused');
    });

    it('keeps the status colours on the status tokens', () => {
        expect(statusColor('active')).toBe('var(--st-ok)');
        expect(statusColor('stale')).toBe('var(--st-warn)');
        expect(statusColor('inactive')).toBe('var(--st-down)');
        expect(statusColor('paused')).toBe('var(--st-off)');
    });
});

describe('seriesRefreshInterval', () => {
    it('refreshes a series four times per step, between the wall interval and five minutes', () => {
        expect(seriesRefreshInterval(15000, 60)).toBe(15000);
        expect(seriesRefreshInterval(5000, 60)).toBe(15000);
        expect(seriesRefreshInterval(5000, 300)).toBe(75000);
        expect(seriesRefreshInterval(5000, 3600)).toBe(300000);
        expect(seriesRefreshInterval(600000, 3600)).toBe(600000);
    });
});
