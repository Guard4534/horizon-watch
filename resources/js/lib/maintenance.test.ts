import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { failedWindowShort } from '@/lib/failedWindow';
import { errorId, invalidField } from '@/lib/fieldErrors';
import {
    fromLocalInput,
    maintenanceUntilText,
    silenceDurationLabel,
    toLocalInput,
} from '@/lib/maintenance';

describe('maintenance times', () => {
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
    it('names every window with the duration fragment', () => {
        expect(failedWindowShort(1440)).toBe('1 d');
        expect(failedWindowShort(10080)).toBe('7 d');
        expect(failedWindowShort(60)).toBe('1 h');
        expect(failedWindowShort(90)).toBe('1 h 30 min');
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

describe('maintenance badge text', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 14, 10, 0));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('names the browser time zone the end is given in', () => {
        expect(
            maintenanceUntilText(
                new Date(2026, 9, 14, 22, 15).toISOString(),
                'en',
            ),
        ).toBe('In maintenance until 22:15 (Europe/Rome)');
    });
});
