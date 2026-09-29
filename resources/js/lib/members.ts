import { trans } from 'laravel-vue-i18n';

export const ASSIGNABLE_ROLES = [
    'admin',
    'member',
    'viewer',
] as const satisfies readonly App.Enums.TeamRole[];

export const VISIBILITIES = [
    'all',
    'non_production',
    'manual',
] as const satisfies readonly App.Enums.MemberVisibility[];

export function visibilityLabel(
    visibility: App.Enums.MemberVisibility,
): string {
    switch (visibility) {
        case 'all':
            return trans('All environments');
        case 'non_production':
            return trans('Everything except production');
        case 'manual':
            return trans('Manual selection');
    }
}

export function assignableRoleName(
    role: (typeof ASSIGNABLE_ROLES)[number],
): string {
    switch (role) {
        case 'admin':
            return trans('Admin');
        case 'member':
            return trans('Member');
        case 'viewer':
            return trans('Viewer');
    }
}

export function roleTagClass(role: App.Enums.TeamRole): string {
    switch (role) {
        case 'owner':
        case 'admin':
            return 'nc-tag nc-tag-accent';
        case 'member':
            return 'nc-tag nc-tag-neutral';
        case 'viewer':
            return 'nc-tag nc-tag-outline';
    }
}

export function losingTheLastAdmin(
    target: { role: App.Enums.TeamRole } | null,
    targetIsSelf: boolean,
    members: readonly { role: App.Enums.TeamRole }[],
): boolean {
    return (
        targetIsSelf &&
        target?.role === 'admin' &&
        members.filter((member) => member.role === 'admin').length === 1
    );
}

export function formatRelative(iso: string | null, locale: string): string {
    if (!iso) {
        return '—';
    }

    const target = new Date(iso);

    if (Number.isNaN(target.getTime())) {
        return '—';
    }

    const seconds = (target.getTime() - Date.now()) / 1000;

    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    const formatter = new Intl.RelativeTimeFormat(locale, {
        numeric: 'auto',
    });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
}
