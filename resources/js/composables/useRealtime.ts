import { echoIsConfigured, useEcho } from '@laravel/echo-vue';

/**
 * Подписка на событие приватного канала, пока компонент на странице. Имя события — как
 * в broadcastAs() на сервере. При SSR и без настроенного Reverb подписки нет.
 */
export function useRealtime<TPayload>(
    channel: string,
    event: string,
    callback: (payload: TPayload) => void,
): void {
    if (import.meta.env.SSR || !echoIsConfigured()) {
        return;
    }

    // Точка в начале: имя события без пространства имён App.Events.
    useEcho<TPayload>(channel, `.${event}`, callback);
}
