import { describe, expect, it } from 'vite-plus/test';
import { losingTheLastAdmin, roleTagClass } from '@/lib/members';

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
