import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { liveRefreshFailed } from '@/composables/useLiveConnection';
import { useRefreshInterval } from '@/composables/useRefreshInterval';

type Poll = ReturnType<typeof router.poll>;

export function useLivePoll(
    only: string[] = ['page', 'openAlertCount', 'panelHealth'],
    every: (ms: number) => number = (ms) => ms,
): void {
    const { interval: refresh } = useRefreshInterval();
    const interval = computed(() => every(refresh.value));
    let poll: Poll | null = null;
    let mounted = false;

    function begin(ms: number): void {
        poll?.destroy();
        poll = router.poll(
            ms,
            {
                only,
                onHttpException: liveRefreshFailed,
                onNetworkError: liveRefreshFailed,
            },
            { autoStart: false, mode: 'rest' },
        );
        poll.start();
    }

    onMounted(() => {
        mounted = true;
        begin(interval.value);
    });

    watch(interval, (ms) => {
        if (mounted) {
            begin(ms);
        }
    });

    onUnmounted(() => {
        mounted = false;
        poll?.destroy();
        poll = null;
    });
}
