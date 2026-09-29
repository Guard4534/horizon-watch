import { useMediaQuery } from '@vueuse/core';
import type { Ref } from 'vue';

export function useIsMobile(): Ref<boolean> {
    return useMediaQuery('(max-width: 639px)');
}
