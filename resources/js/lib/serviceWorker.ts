/**
 * Регистрирует service worker (resources/js/sw.ts) в собранном приложении: без него
 * приложение не ставится на телефон, а без сети вместо страницы «Нет сети» видна ошибка
 * браузера. С Vite dev server service worker не нужен: он снимается, если остался
 * от собранной версии.
 */
export function initializeServiceWorker(): void {
    if (import.meta.env.SSR || !('serviceWorker' in navigator)) {
        return;
    }

    if (import.meta.env.DEV) {
        void navigator.serviceWorker
            .getRegistrations()
            .then((registrations) =>
                registrations.forEach(
                    (registration) => void registration.unregister(),
                ),
            );

        return;
    }

    const register = () =>
        void navigator.serviceWorker.register('/sw.js', { scope: '/' });

    // После загрузки страницы: предкэш не отнимает сеть у самой страницы.
    if (document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
}
