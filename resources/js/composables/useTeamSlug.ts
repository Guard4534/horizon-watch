import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

// Monitoring routes live under /{current_team}; every page is reached through
// EnsureTeamMembership, so a current team is always present there.
export function useTeamSlug(): ComputedRef<string> {
    const page = usePage();

    return computed(() => page.props.currentTeam?.slug ?? '');
}
