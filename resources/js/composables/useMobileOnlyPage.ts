import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, watch } from 'vue';
import { useIsMobile } from '@/composables/useIsMobile';
import type { RouteDefinition } from '@/wayfinder';

export function useMobileOnlyPage(
    fallback: RouteDefinition<'get'>,
): Ref<boolean> {
    const isMobile = useIsMobile();

    const guard = (mobile: boolean): void => {
        if (!mobile) {
            router.visit(fallback, { replace: true });
        }
    };

    onMounted(() => guard(isMobile.value));

    watch(isMobile, guard);

    return isMobile;
}
