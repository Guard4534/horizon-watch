import '@fontsource-variable/inter';
import { createInertiaApp } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Horizon Watch';

const translations = import.meta.glob<Record<string, string>>(
    '../../lang/*.json',
    { import: 'default' },
);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
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
            lang: document.documentElement.lang || 'en',
            resolve: async (lang: string) =>
                (await translations[`../../lang/${lang}.json`]?.()) ?? {},
        });
    },
});

initializeFlashToast();
