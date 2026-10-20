import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import {
    currentSubscription,
    pushAvailability,
    saveSubscription,
    subscribe,
    unsubscribe,
} from '@/lib/push';

/**
 * Пуши на этом устройстве:
 * - `loading` — ещё проверяем, до монтирования и при SSR;
 * - `on` и `off` — включены или нет;
 * - `denied` — уведомления запрещены в настройках браузера;
 * - `install` — iPhone: сначала установить приложение на экран «Домой»;
 * - `unsupported` — браузер пуши не умеет.
 */
export type PushState =
    | 'loading'
    | 'on'
    | 'off'
    | 'denied'
    | 'install'
    | 'unsupported';

export type UsePushNotificationsReturn = {
    state: Ref<PushState>;
    /** Разрешение ещё не спрашивали: для баннера с предложением включить. */
    askable: Ref<boolean>;
    busy: Ref<boolean>;
    error: Ref<string | null>;
    enable: () => Promise<void>;
    disable: () => Promise<void>;
};

export function usePushNotifications(): UsePushNotificationsReturn {
    const page = usePage();
    const state = ref<PushState>('loading');
    const askable = ref(false);
    const busy = ref(false);
    const error = ref<string | null>(null);
    let permissionStatus: PermissionStatus | null = null;

    onMounted(async () => {
        const availability = pushAvailability();

        if (availability !== 'available' || !page.props.vapidPublicKey) {
            state.value =
                availability === 'install' ? 'install' : 'unsupported';

            return;
        }

        void watchPermission();
        askable.value = Notification.permission === 'default';

        if (Notification.permission === 'denied') {
            state.value = 'denied';

            return;
        }

        try {
            const subscription = await currentSubscription();

            if (subscription === null) {
                state.value = import.meta.env.DEV ? 'unsupported' : 'off';

                return;
            }

            state.value = Notification.permission === 'granted' ? 'on' : 'off';

            // Подписку браузера сервер мог удалить (сервис пушей ответил, что её нет),
            // или до этого на устройстве был другой пользователь.
            if (state.value === 'on') {
                void saveSubscription(subscription).catch(() => {});
            }
        } catch {
            state.value = 'unsupported';
        }
    });

    onBeforeUnmount(() => {
        if (permissionStatus !== null) {
            permissionStatus.onchange = null;
        }
    });

    /**
     * Разрешение меняется и без кнопок страницы. Chrome на Android может показать тихий
     * запрос, который сам закрывается через несколько секунд, а после нескольких
     * закрытых запросов блокирует сайт. Пользователь мог и разрешить уведомления в
     * настройках сайта.
     */
    async function watchPermission(): Promise<void> {
        try {
            permissionStatus = await navigator.permissions.query({
                name: 'notifications',
            });
        } catch {
            return;
        }

        permissionStatus.onchange = () => {
            const permission = Notification.permission;

            askable.value = permission === 'default';
            error.value = null;

            if (permission === 'denied') {
                state.value = 'denied';
            } else if (permission === 'default' || state.value === 'denied') {
                // Без разрешения браузер снимает подписку, а выданное разрешение
                // ещё нужно подтвердить кнопкой: подписку создаёт она.
                state.value = 'off';
            }
        };
    }

    async function enable(): Promise<void> {
        const publicKey = page.props.vapidPublicKey;

        if (!publicKey || busy.value) {
            return;
        }

        busy.value = true;
        error.value = null;

        try {
            const permission = await subscribe(publicKey);

            askable.value = permission === 'default';
            state.value =
                permission === 'granted'
                    ? 'on'
                    : permission === 'denied'
                      ? 'denied'
                      : 'off';

            // Запрос закрыли или он закрылся сам, не дождавшись ответа.
            if (permission === 'default') {
                error.value =
                    'Браузер не получил разрешения. Нажмите «Включить» ещё раз и выберите «Разрешить».';
            }
        } catch {
            error.value =
                'Не удалось включить уведомления. Проверьте соединение и попробуйте ещё раз.';
        } finally {
            busy.value = false;
        }
    }

    async function disable(): Promise<void> {
        if (busy.value) {
            return;
        }

        busy.value = true;
        error.value = null;

        try {
            await unsubscribe();
            state.value = 'off';
        } catch {
            error.value =
                'Не удалось выключить уведомления. Проверьте соединение и попробуйте ещё раз.';
        } finally {
            busy.value = false;
        }
    }

    return { state, askable, busy, error, enable, disable };
}
