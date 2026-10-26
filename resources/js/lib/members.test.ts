import { describe, expect, it } from 'vite-plus/test';
import { roleTagClass } from '@/lib/members';

describe('roleTagClass', () => {
    it('gives owners and admins the accent tag', () => {
        expect(roleTagClass('owner')).toBe('nc-tag nc-tag-accent');
        expect(roleTagClass('admin')).toBe('nc-tag nc-tag-accent');
        expect(roleTagClass('viewer')).toBe('nc-tag nc-tag-outline');
    });
});
