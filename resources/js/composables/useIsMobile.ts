import type { Ref } from 'vue';
import { ref } from 'vue';

const QUERY = '(max-width: 639px)';

let isMobile: Ref<boolean> | null = null;

// One listener for the whole app, created on first use and kept: the
// viewport outlives every component that asks about it.
export function useIsMobile(): Ref<boolean> {
    if (isMobile !== null) {
        return isMobile;
    }

    // No matchMedia (server rendering, old embedded browsers, tests): the
    // desktop layout is the one that works everywhere. Not cached, so a
    // later call in a real browser still gets the listener.
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return ref(false);
    }

    const media = window.matchMedia(QUERY);
    const current = ref(media.matches);
    const update = (event: MediaQueryListEvent) => {
        current.value = event.matches;
    };

    // Safari 13 and older only have the deprecated addListener().
    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', update);
    } else {
        media.addListener(update);
    }

    isMobile = current;

    return current;
}
