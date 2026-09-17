import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export function useTeamSlug(): ComputedRef<string> {
    const page = usePage();

    return computed(() => page.props.currentTeam?.slug ?? '');
}
