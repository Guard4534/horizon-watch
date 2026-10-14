import { router } from '@inertiajs/vue3';

export function replaceVisit(
    url: string,
    only: string[],
    data: Record<string, string> = {},
): void {
    router.cancelAll({ sync: false, prefetch: false });
    router.get(url, data, {
        only,
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
