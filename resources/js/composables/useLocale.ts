import { router, usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { update } from '@/routes/locale';

export function useLocale(): {
    locale: ComputedRef<App.Enums.Locale>;
    setLocale: (next: App.Enums.Locale) => void;
} {
    const page = usePage();
    const locale = computed(() => page.props.locale);

    function setLocale(next: App.Enums.Locale): void {
        router.patch(
            update().url,
            { locale: next },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => void loadLanguageAsync(next),
            },
        );
    }

    return { locale, setLocale };
}
