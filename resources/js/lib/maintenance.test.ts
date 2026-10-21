import { describe, expect, it } from 'vite-plus/test';
import { failedWindowShort } from '@/lib/failedWindow';
import { errorId, invalidField } from '@/lib/fieldErrors';
import {
    formatMaintenanceTime,
    fromLocalInput,
    silenceDurationLabel,
    toLocalInput,
} from '@/lib/maintenance';

describe('maintenance times', () => {
    const now = new Date(2026, 9, 14, 10, 0);

    it('shows the date only for another day', () => {
        expect(
            formatMaintenanceTime(
                new Date(2026, 9, 14, 22, 15).toISOString(),
                'en',
                now,
            ),
        ).toBe('22:15');
        expect(
            formatMaintenanceTime(
                new Date(2026, 9, 16, 6, 0).toISOString(),
                'en',
                now,
            ),
        ).toBe('Fri, Oct 16, 06:00');
    });

    it('round-trips a datetime-local value through UTC', () => {
        const at = new Date(2026, 9, 25, 2, 30);

        expect(toLocalInput(at)).toBe('2026-10-25T02:30');
        expect(fromLocalInput('2026-07-01T09:00')).toBe(
            '2026-07-01T07:00:00.000Z',
        );
        expect(fromLocalInput('')).toBe('');
    });

    it('names a silence in hours when it can', () => {
        expect(silenceDurationLabel(30)).toBe('For 30 minutes');
        expect(silenceDurationLabel(60)).toBe('For 1 hour');
        expect(silenceDurationLabel(120)).toBe('For 2 hours');
        expect(silenceDurationLabel(90)).toBe('For 90 minutes');
    });
});

describe('failed windows', () => {
    it('names the usual windows in short form and any other one in minutes', () => {
        expect(failedWindowShort(1440)).toBe('24h');
        expect(failedWindowShort(10080)).toBe('7 d');
        expect(failedWindowShort(60)).toBe('60 min');
    });
});

describe('field errors', () => {
    it('links an invalid field to its message', () => {
        expect(errorId('email')).toBe('email-error');
        expect(invalidField('email', 'Required')).toEqual({
            'aria-invalid': true,
            'aria-describedby': 'email-error',
        });
        expect(invalidField('email', null)).toEqual({});
        expect(invalidField('email')).toEqual({});
    });
});
