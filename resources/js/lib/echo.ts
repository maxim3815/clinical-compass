import { configureEcho } from '@laravel/echo-vue';
import { authenticate } from '@/actions/Illuminate/Broadcasting/BroadcastController';

/**
 * Обновления в реальном времени (чат, счётчики непрочитанного) приходят через Reverb.
 * При SSR соединения нет. Без ключа Reverb в .env страницы работают как обычно, только
 * без обновлений: новое видно после перезагрузки.
 */
export function initializeEcho(): void {
    if (import.meta.env.SSR || !import.meta.env.VITE_REVERB_APP_KEY) {
        return;
    }

    configureEcho({
        broadcaster: 'reverb',
        // После входа Inertia не перезагружает страницу, а токен CSRF при входе меняется,
        // поэтому токен берётся из cookie при каждой авторизации канала.
        channelAuthorization: {
            endpoint: authenticate.url(),
            transport: 'ajax',
            headersProvider: () => ({ 'X-XSRF-TOKEN': xsrfToken() }),
        },
    });
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
