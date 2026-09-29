import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export function useVisibility(): {
    canManageApplications: ComputedRef<boolean>;
    somethingIsHidden: ComputedRef<boolean>;
} {
    const page = usePage();

    return {
        canManageApplications: computed(() => page.props.canManageApplications),
        somethingIsHidden: computed(
            () =>
                page.props.visibilityRestricted &&
                page.props.organizationHasEnvironments,
        ),
    };
}
