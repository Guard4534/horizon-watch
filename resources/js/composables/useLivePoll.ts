import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';
import { useRefreshInterval } from '@/composables/useRefreshInterval';

type Poll = ReturnType<typeof router.poll>;

/**
 * Reloads the given props at the interval picked in the header.
 *
 * Inertia's usePoll() reads its interval once, so a changed header menu
 * would keep the old cadence until the next visit: the poll is rebuilt
 * instead. "rest" waits for a reload to finish before counting down again,
 * so a slow page on the 5 s setting never stacks requests.
 */
export function useLivePoll(only: string[]): void {
    const { interval } = useRefreshInterval();
    let poll: Poll | null = null;
    let mounted = false;

    function begin(ms: number): void {
        poll?.destroy();
        poll = router.poll(ms, { only }, { autoStart: false, mode: 'rest' });
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
