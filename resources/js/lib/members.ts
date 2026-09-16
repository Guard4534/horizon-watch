import { trans } from 'laravel-vue-i18n';

// The roles a role change or an invitation may assign. "owner" is not one of
// them: handing the organization over is not a flow that exists, and
// TeamPolicy::updateMember refuses to touch the owner from the server side.
// TeamRole::assignable() is the same list in PHP.
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

// MemberVisibility::label() says the same thing in PHP; the interface needs
// it in the reader's language, so the strings live in real trans() calls
// rather than in a lookup table (the translation test only sees call sites).
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

// From the plan: accent for admin and owner, neutral for member, outline for
// viewer.
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

// "2 days ago", "in 5 days" — the mockup's phrasing for an invitation's sent
// and expiry columns. Intl does the wording in the reader's language, so
// nothing here needs translating and nothing is fetched.
export function formatRelative(iso: string | null): string {
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

    const formatter = new Intl.RelativeTimeFormat(
        document.documentElement.lang || 'en',
        { numeric: 'auto' },
    );

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
}
