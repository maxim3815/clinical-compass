import { createInertiaApp } from '@inertiajs/vue3';
import { initializeInstallPrompt } from '@/composables/useInstallApp';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeEcho } from '@/lib/echo';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeOfflineVisits } from '@/lib/offlineVisits';
import { initializeServiceWorker } from '@/lib/serviceWorker';

const appName = import.meta.env.VITE_APP_NAME || 'Клинический компас';

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
            case name.startsWith('access-link/'):
            case name.startsWith('invitation/'):
            case name.startsWith('join/'):
            case name.startsWith('password-reset/'):
            case name === 'Consent':
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        // Vue перехватывает ошибки компонентов и только пишет их в консоль. Передаём их
        // в window как необработанные, иначе браузерные тесты их не видят.
        if (!import.meta.env.SSR) {
            app.config.errorHandler = (error) => {
                window.reportError(error);
            };
        }

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: 'var(--primary)',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();

initializeEcho();

// Приложение на телефоне: service worker, переходы без сети и предложение установки.
initializeServiceWorker();
initializeOfflineVisits();
initializeInstallPrompt();
