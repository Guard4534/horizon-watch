import { describe, expect, it } from 'vite-plus/test';
import {
    envTypeColor,
    formatCount,
    formatDuration,
    formatElapsed,
    formatWait,
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

describe('formatWait', () => {
    it.each([
        [0, '0s'],
        [42.4, '42s'],
        [90, '2m'],
        [59 * 60, '59m'],
        [3600, '1h'],
        [5400, '1h 30m'],
        [26 * 3600 + 60, '26h 1m'],
    ])('formats %d seconds as %s', (seconds, expected) => {
        expect(formatWait(seconds)).toBe(expected);
    });
});

describe('formatDuration', () => {
    it.each([
        [45, '45s'],
        [120, '2m'],
        [125, '2m 5s'],
        [3600, '1h'],
        [3700, '1h 1m'],
    ])('formats %d seconds as %s', (seconds, expected) => {
        expect(formatDuration(seconds)).toBe(expected);
    });
});

describe('formatElapsed', () => {
    it.each([
        [0, 'less than a minute ago'],
        [5, '5 min ago'],
        [125, '2 h ago'],
        [3000, '2 d ago'],
    ])('formats %d minutes as %s', (minutes, expected) => {
        expect(formatElapsed(minutes)).toBe(expected);
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
