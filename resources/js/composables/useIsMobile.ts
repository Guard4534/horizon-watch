import type { Ref } from 'vue';
import { ref } from 'vue';

const QUERY = '(max-width: 639px)';

let isMobile: Ref<boolean> | null = null;

export function useIsMobile(): Ref<boolean> {
    if (isMobile !== null) {
        return isMobile;
    }

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

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', update);
    } else {
        media.addListener(update);
    }

    isMobile = current;

    return current;
}
