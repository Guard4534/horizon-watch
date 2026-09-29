import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export function useCurrentPath(): {
    path: ComputedRef<string>;
    startsWith: (...prefixes: string[]) => boolean;
} {
    const page = usePage();
    const path = computed(() => page.url.split('?')[0]);

    return {
        path,
        startsWith: (...prefixes: string[]) =>
            prefixes.some((prefix) => path.value.startsWith(prefix)),
    };
}
