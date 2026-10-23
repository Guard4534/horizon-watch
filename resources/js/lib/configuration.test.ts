import { describe, expect, it } from 'vite-plus/test';
import { ruleLabel } from '@/lib/alertRules';
import {
    importItemLabel,
    importOutcome,
    importStatusClass,
    importStatusLabel,
    importTotals,
} from '@/lib/configuration';

const item = (
    overrides: Partial<App.Data.Configuration.ImportItemData> = {},
): App.Data.Configuration.ImportItemData => ({
    kind: 'application',
    label: 'Acme Shop',
    application: null,
    scope: null,
    metric: null,
    type: null,
    status: 'new',
    changes: [],
    errors: [],
    passwordNeeded: false,
    ...overrides,
});

describe('importTotals', () => {
    const items = [
        item(),
        item({ status: 'changed', changes: ['name'] }),
        item({ status: 'unchanged' }),
        item({ status: 'invalid', errors: ['The name field is required.'] }),
    ];

    it('leaves the changed items as they are while the switch is off', () => {
        expect(importTotals(items, false)).toEqual({
            create: 1,
            update: 0,
            leave: 2,
            invalid: 1,
        });
    });

    it('counts the changed items as updates when the switch is on', () => {
        expect(importTotals(items, true)).toEqual({
            create: 1,
            update: 1,
            leave: 1,
            invalid: 1,
        });
    });
});

describe('importOutcome', () => {
    it('says what happens to a changed item', () => {
        expect(importOutcome(item({ status: 'changed' }), true)).toBe(
            'will be updated',
        );
        expect(importOutcome(item({ status: 'changed' }), false)).toBe(
            'left as it is',
        );
    });

    it('says nothing for the other items', () => {
        expect(importOutcome(item({ status: 'new' }), true)).toBeNull();
        expect(importOutcome(item({ status: 'unchanged' }), false)).toBeNull();
    });
});

describe('importItemLabel', () => {
    it('names an environment under its application', () => {
        expect(
            importItemLabel(
                item({
                    kind: 'environment',
                    label: 'production',
                    application: 'Acme Shop',
                }),
            ),
        ).toBe('Acme Shop / production');
    });

    it('names a rule by its scope and the label of its metric', () => {
        expect(
            importItemLabel(
                item({
                    kind: 'alert_rule',
                    label: 'organization / queue.pending',
                    scope: 'organization',
                    metric: 'queue.pending',
                }),
            ),
        ).toBe(`Organization / ${ruleLabel('queue.pending')}`);
        expect(
            importItemLabel(
                item({
                    kind: 'alert_rule',
                    label: 'staging / queue.max_wait',
                    scope: 'staging',
                    metric: 'queue.max_wait',
                }),
            ),
        ).toBe(`staging / ${ruleLabel('queue.max_wait')}`);
    });

    it('falls back to the words of the file for an unknown metric', () => {
        expect(
            importItemLabel(
                item({
                    kind: 'alert_rule',
                    label: 'organization / queue.length',
                    scope: 'organization',
                    metric: null,
                    status: 'invalid',
                }),
            ),
        ).toBe('organization / queue.length');
    });
});

describe('importStatusLabel and importStatusClass', () => {
    it('give every status its word and its tag', () => {
        expect(
            (['new', 'changed', 'unchanged', 'invalid'] as const).map(
                (status) => [
                    importStatusLabel(status),
                    importStatusClass(status),
                ],
            ),
        ).toEqual([
            ['New', 'nc-tag-accent'],
            ['Changed', 'nc-tag-outline'],
            ['Unchanged', 'nc-tag-neutral'],
            ['Invalid', 'nc-tone-down is-invalid'],
        ]);
    });
});
