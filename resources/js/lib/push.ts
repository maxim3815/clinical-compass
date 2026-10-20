import { http } from '@inertiajs/vue3';
import PushSubscriptionController from '@/actions/App/Http/Controllers/Settings/PushSubscriptionController';
import { isIos, isStandalone } from '@/composables/useInstallApp';

/**
 * Пуши в этом браузере: можно включить, на iPhone сначала нужно установить приложение,
 * или браузер их не умеет.
 */
export type PushAvailability = 'available' | 'install' | 'unsupported';

export function pushAvailability(): PushAvailability {
    // На iPhone пуши приходят только в приложение на экране «Домой».
    if (isIos() && !isStandalone()) {
        return 'install';
    }

    return 'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
        ? 'available'
        : 'unsupported';
}

/**
 * Service worker приложения. В разработке его нет (lib/serviceWorker.ts), и пуши недоступны.
 */
async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (import.meta.env.DEV) {
        return null;
    }

    return navigator.serviceWorker.ready;
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    const worker = await registration();

    return worker === null ? null : worker.pushManager.getSubscription();
}

/**
 * Подписка браузера уходит на сервер: пуши этого устройства получает тот, кто сейчас вошёл.
 */
export async function saveSubscription(
    subscription: PushSubscription,
): Promise<void> {
    const { endpoint, keys } = subscription.toJSON();
    const encodings = (
        PushManager as typeof PushManager & {
            supportedContentEncodings?: readonly string[];
        }
    ).supportedContentEncodings;

    await http.getClient().request({
        ...PushSubscriptionController.store(),
        data: {
            endpoint,
            keys,
            contentEncoding:
                encodings === undefined || encodings.includes('aes128gcm')
                    ? 'aes128gcm'
                    : 'aesgcm',
        },
    });
}

/**
 * Спрашивает разрешение и подписывает браузер. Возвращает разрешение: если оно не выдано,
 * подписки нет.
 */
export async function subscribe(
    publicKey: string,
): Promise<NotificationPermission> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return permission;
    }

    const worker = await registration();

    if (worker === null) {
        throw new Error('Service worker не зарегистрирован');
    }

    const key = fromBase64Url(publicKey);
    let subscription = await worker.pushManager.getSubscription();

    // Подписка на прежний ключ сервера больше не работает: браузер не даст подписаться заново,
    // пока её не снять.
    if (
        subscription !== null &&
        !sameKey(subscription.options.applicationServerKey, key)
    ) {
        await subscription.unsubscribe();
        subscription = null;
    }

    subscription ??= await worker.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
    });

    await saveSubscription(subscription);

    return permission;
}

/**
 * Снимает подписку этого браузера и на сервере, и в самом браузере.
 */
export async function unsubscribe(): Promise<void> {
    const subscription = await currentSubscription();

    if (subscription === null) {
        return;
    }

    await http.getClient().request({
        ...PushSubscriptionController.destroy(),
        data: { endpoint: subscription.endpoint },
    });

    await subscription.unsubscribe();
}

/**
 * При выходе из аккаунта: иначе на общем устройстве пуши получал бы прежний пользователь.
 * Выход не ждёт дольше пары секунд, даже если сервис пушей не отвечает.
 */
export async function unsubscribeBeforeLogout(): Promise<void> {
    if (pushAvailability() !== 'available') {
        return;
    }

    await Promise.race([
        unsubscribe().catch(() => {}),
        new Promise((resolve) => setTimeout(resolve, 2000)),
    ]);
}

function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
    const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

function sameKey(current: ArrayBuffer | null, key: Uint8Array): boolean {
    if (current === null || current.byteLength !== key.length) {
        return false;
    }

    const bytes = new Uint8Array(current);

    return key.every((byte, index) => bytes[index] === byte);
}
