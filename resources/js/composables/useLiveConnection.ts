import { router } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRefreshInterval } from '@/composables/useRefreshInterval';

const GRACE_MS = 10000;

const lastSuccess = ref(Date.now());
const failing = ref(false);

function succeeded(): void {
    lastSuccess.value = Date.now();
    failing.value = false;
}

export function liveRefreshFailed(): false {
    failing.value = true;

    return false;
}

export function useLiveConnection(): {
    connected: ComputedRef<boolean>;
} {
    const { interval } = useRefreshInterval();
    const now = ref(Date.now());
    const online = ref(true);
    let timer: ReturnType<typeof setInterval> | undefined;
    let stopListening: (() => void) | undefined;

    function sync(): void {
        online.value = navigator.onLine;
        now.value = Date.now();
    }

    function resume(): void {
        if (document.visibilityState === 'visible') {
            lastSuccess.value = Date.now();
            sync();
        }
    }

    onMounted(() => {
        succeeded();
        sync();
        timer = setInterval(sync, 5000);
        stopListening = router.on('success', succeeded);
        window.addEventListener('online', sync);
        window.addEventListener('offline', sync);
        document.addEventListener('visibilitychange', resume);
    });

    onUnmounted(() => {
        clearInterval(timer);
        stopListening?.();
        window.removeEventListener('online', sync);
        window.removeEventListener('offline', sync);
        document.removeEventListener('visibilitychange', resume);
    });

    const connected = computed(
        () =>
            online.value &&
            !failing.value &&
            (document.visibilityState !== 'visible' ||
                now.value - lastSuccess.value <= 2 * interval.value + GRACE_MS),
    );

    return { connected };
}
