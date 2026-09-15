import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, ref } from 'vue';

function stamp(): string {
    return new Date().toTimeString().slice(0, 8);
}

export function useLastRefresh(): Ref<string> {
    const updatedAt = ref(stamp());
    const stop = router.on('success', () => {
        updatedAt.value = stamp();
    });

    onBeforeUnmount(stop);

    return updatedAt;
}
