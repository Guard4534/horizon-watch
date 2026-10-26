import { describe, expect, it } from 'vite-plus/test';
import { lampColor, lampLabel } from '@/components/nocturne/status';

describe('lampColor', () => {
    it('takes the colour of the status', () => {
        expect(lampColor('active')).toBe('var(--st-ok)');
        expect(lampColor('inactive')).toBe('var(--st-down)');
    });

    it('is neutral without a status', () => {
        expect(lampColor(null)).toBe('var(--nc-neutral-500)');
    });

    it('lets the caller tone win, for evaluated states such as a paused collection', () => {
        expect(lampColor('active', 'var(--st-off)')).toBe('var(--st-off)');
        expect(lampColor(null, 'var(--st-warn)')).toBe('var(--st-warn)');
    });
});

describe('lampLabel', () => {
    it('names the status', () => {
        expect(lampLabel('degraded')).toBe('degraded');
    });

    it('says there is no reading yet without a status', () => {
        expect(lampLabel(null)).toBe('No reading yet');
    });

    it('lets the caller label win', () => {
        expect(lampLabel(null, 'Collection paused')).toBe('Collection paused');
        expect(lampLabel('active', 'production · active')).toBe(
            'production · active',
        );
    });
});
