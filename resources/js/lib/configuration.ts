import { trans } from 'laravel-vue-i18n';
import { ruleLabel } from '@/lib/alertRules';

type ImportItem = App.Data.Configuration.ImportItemData;

export type ImportTotals = {
    create: number;
    update: number;
    leave: number;
    invalid: number;
};

export function importTotals(
    items: ImportItem[],
    update: boolean,
): ImportTotals {
    const totals: ImportTotals = { create: 0, update: 0, leave: 0, invalid: 0 };

    for (const item of items) {
        switch (item.status) {
            case 'new':
                totals.create++;
                break;
            case 'changed':
                if (update) {
                    totals.update++;
                } else {
                    totals.leave++;
                }
                break;
            case 'unchanged':
                totals.leave++;
                break;
            case 'invalid':
                totals.invalid++;
                break;
        }
    }

    return totals;
}

export function importStatusLabel(status: App.Enums.ImportItemStatus): string {
    switch (status) {
        case 'new':
            return trans('New');
        case 'changed':
            return trans('Changed');
        case 'unchanged':
            return trans('Unchanged');
        case 'invalid':
            return trans('Invalid');
    }
}

export function importStatusClass(status: App.Enums.ImportItemStatus): string {
    switch (status) {
        case 'new':
            return 'nc-tag-accent';
        case 'changed':
            return 'nc-tag-outline';
        case 'unchanged':
            return 'nc-tag-neutral';
        case 'invalid':
            return 'nc-tone-down is-invalid';
    }
}

export function importOutcome(
    item: ImportItem,
    update: boolean,
): string | null {
    if (item.status !== 'changed') {
        return null;
    }

    return update ? trans('will be updated') : trans('left as it is');
}

export function importItemLabel(item: ImportItem): string {
    switch (item.kind) {
        case 'application':
            return item.label;
        case 'environment':
            return `${item.application ?? '—'} / ${item.label}`;
        case 'alert_rule': {
            if (item.metric === null || item.scope === null) {
                return item.label;
            }

            const scope =
                item.scope === 'organization'
                    ? trans('Organization')
                    : item.scope;

            return `${scope} / ${ruleLabel(item.metric)}`;
        }
    }
}
