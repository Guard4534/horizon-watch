import type { Ref } from 'vue';
import { ref } from 'vue';

const STORAGE_KEY = 'horizon-watch.refresh-interval';
const OPTIONS = [5000, 10000, 15000, 30000, 60000, 300000];
const DEFAULT_INTERVAL = 15000;

function stored(): number {
    try {
        const value = Number(window.localStorage.getItem(STORAGE_KEY));

        return OPTIONS.includes(value) ? value : DEFAULT_INTERVAL;
    } catch {
        return DEFAULT_INTERVAL;
    }
}

const interval = ref(stored());

export function useRefreshInterval(): {
    interval: Ref<number>;
    options: number[];
    set: (ms: number) => void;
} {
    function set(ms: number): void {
        if (!OPTIONS.includes(ms)) {
            return;
        }

        interval.value = ms;

        try {
            window.localStorage.setItem(STORAGE_KEY, String(ms));
        } catch {}
    }

    return { interval, options: [...OPTIONS], set };
}
