import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    formatRelative,
    losingTheLastAdmin,
    roleTagClass,
} from '@/lib/members';

const admin = { role: 'admin' as const };
const member = { role: 'member' as const };
const owner = { role: 'owner' as const };

describe('losingTheLastAdmin', () => {
    it('stops the only admin from leaving the admins', () => {
        expect(losingTheLastAdmin(admin, true, [owner, admin, member])).toBe(
            true,
        );
    });

    it('lets an admin go when another admin remains', () => {
        expect(losingTheLastAdmin(admin, true, [admin, admin, member])).toBe(
            false,
        );
    });

    it('only guards changes to yourself', () => {
        expect(losingTheLastAdmin(admin, false, [admin, member])).toBe(false);
    });

    it('does not count the owner as an admin to lose', () => {
        expect(losingTheLastAdmin(owner, true, [owner, member])).toBe(false);
        expect(losingTheLastAdmin(null, true, [admin])).toBe(false);
    });
});

describe('roleTagClass', () => {
    it('gives owners and admins the accent tag', () => {
        expect(roleTagClass('owner')).toBe('nc-tag nc-tag-accent');
        expect(roleTagClass('admin')).toBe('nc-tag nc-tag-accent');
        expect(roleTagClass('viewer')).toBe('nc-tag nc-tag-outline');
    });
});

describe('formatRelative', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-14T10:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('picks the largest whole unit', () => {
        expect(formatRelative('2026-10-17T10:00:00Z', 'en')).toBe('in 3 days');
        expect(formatRelative('2026-10-14T08:00:00Z', 'en')).toBe(
            '2 hours ago',
        );
        expect(formatRelative('2026-10-14T10:05:00Z', 'en')).toBe(
            'in 5 minutes',
        );
        expect(formatRelative('2026-10-15T10:00:00Z', 'it')).toBe('domani');
    });

    it('shows a dash for a missing or broken date', () => {
        expect(formatRelative(null, 'en')).toBe('—');
        expect(formatRelative('not a date', 'en')).toBe('—');
    });
});
