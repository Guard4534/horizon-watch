import '@fontsource-variable/inter';
import { createInertiaApp, router } from '@inertiajs/vue3';
import {
    getActiveLanguage,
    i18nVue,
    loadLanguageAsync,
} from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Horizon Watch';

type Messages = { default: Record<string, string> };

const translations = import.meta.glob<Messages>('../../lang/*.json');

const loadMessages = (lang: string): Promise<Messages> =>
    (
        translations[`../../lang/${lang}.json`]?.() ??
        Promise.resolve({ default: {} })
    ).catch(() => ({ default: {} }));

const initialLanguage = document.documentElement.lang || 'en';

void loadMessages(initialLanguage).then((initialMessages) =>
    createInertiaApp({
        title: (title, page) => {
            const full = title ? `${title} - ${appName}` : appName;
            const open = page.props.openAlertCount;

            return typeof open === 'number' && open > 0
                ? `(${open}) ${full}`
                : full;
        },
        layout: (name) => {
            switch (true) {
                case name.startsWith('auth/'):
                case name === 'Error':
                    return AuthLayout;
                case name.startsWith('settings/'):
                case name.startsWith('teams/'):
                    return [AppLayout, SettingsLayout];
                default:
                    return AppLayout;
            }
        },
        progress: {
            color: '#9184d9',
        },
        withApp(app) {
            app.use(i18nVue, {
                lang: initialLanguage,
                resolve: (lang: string) =>
                    lang === initialLanguage
                        ? Promise.resolve(initialMessages)
                        : loadMessages(lang),
            });
        },
    }),
);

router.on('success', (event) => {
    const locale = event.detail.page.props.locale;

    if (locale && locale !== getActiveLanguage()) {
        void loadLanguageAsync(locale);
    }
});

initializeFlashToast();
