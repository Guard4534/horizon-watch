import { describe, expect, it } from 'vite-plus/test';
import { getInitials } from '@/lib/initials';

describe('getInitials', () => {
    it.each([
        [undefined, ''],
        ['', ''],
        ['   ', ''],
        ['ada', 'A'],
        ['Ada Lovelace', 'AL'],
        ['  ada   byron  lovelace ', 'AL'],
        ['élodie über', 'ÉÜ'],
        ['😀 Smile', '😀S'],
    ])('reads %s as %s', (name, expected) => {
        expect(getInitials(name)).toBe(expected);
    });
});
