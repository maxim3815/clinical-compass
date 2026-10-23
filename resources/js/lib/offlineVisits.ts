import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

// Переход, который сейчас ждёт ответа сервера.
type CurrentVisit = {
    id: string;
    method: string;
    url: URL;
    partial: boolean;
};

/**
 * Без сети переход Inertia не доходит до сервера, и страница просто не меняется.
 * Переход по странице повторяем обычной загрузкой: её перехватывает service worker
 * и показывает страницу «Нет сети». Отправку формы и частичную перезагрузку не повторяем,
 * а сообщаем об ошибке: введённые данные остаются в форме.
 *
 * Сервер лежит или перезапускается (прокси отвечает 502–504, приложение без базы — 500):
 * вместо окна с сырой страницей ошибки сообщаем, что сервер не отвечает, и остаёмся на
 * странице. В разработке окно оставляем: в нём текст исключения.
 *
 * Фоновые запросы (async, предзагрузка) не трогаем.
 */
export function initializeOfflineVisits(): void {
    if (import.meta.env.SSR) {
        return;
    }

    let current: CurrentVisit | null = null;

    router.on('start', (event) => {
        const { visit } = event.detail;

        if (visit.async || visit.prefetch) {
            return;
        }

        current = {
            id: visit.id,
            method: visit.method,
            url: visit.url,
            partial: visit.only.length > 0 || visit.except.length > 0,
        };
    });

    router.on('finish', (event) => {
        if (event.detail.visit.id === current?.id) {
            current = null;
        }
    });

    router.on('networkError', (event) => {
        if (current === null) {
            return;
        }

        event.preventDefault();

        if (current.method === 'get' && !current.partial) {
            window.location.assign(current.url.href);

            return;
        }

        toast.error(
            'Нет подключения к интернету. Попробуйте ещё раз, когда появится сеть.',
        );
    });

    router.on('httpException', (event) => {
        if (import.meta.env.DEV || event.detail.response.status < 500) {
            return;
        }

        event.preventDefault();

        if (current === null) {
            return;
        }

        toast.error(
            'Сервер временно не отвечает. Попробуйте ещё раз через минуту.',
        );
    });
}
