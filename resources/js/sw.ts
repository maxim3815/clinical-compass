/// <reference lib="webworker" />

/**
 * Service worker приложения: собирается vite-plugin-pwa в public/sw.js, список предкэша
 * Workbox вставляет при сборке. Регистрация — resources/js/lib/serviceWorker.ts.
 *
 * Кэшируются только собранные ассеты, иконки и страница «Нет сети». Переходы по страницам
 * всегда идут в сеть, а ответы Inertia, JSON и WebSocket service worker не трогает: данные
 * пациента на устройстве не хранятся, а новая версия приложения приходит без действий.
 */
import { clientsClaim } from 'workbox-core';
import type { PrecacheEntry } from 'workbox-precaching';
import {
    cleanupOutdatedCaches,
    matchPrecache,
    precacheAndRoute,
} from 'workbox-precaching';
import {
    NavigationRoute,
    registerRoute,
    setCatchHandler,
} from 'workbox-routing';
import { NetworkOnly } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope & {
    __WB_MANIFEST: (PrecacheEntry | string)[];
};

const OFFLINE_PAGE = '/offline.html';

// Новая версия включается сразу, без вкладки «обновить»: старые ассеты остаются
// у открытой страницы в памяти, а при следующем переходе Inertia сама перезагрузит
// страницу, если версия ассетов сменилась.
void self.skipWaiting();
clientsClaim();

cleanupOutdatedCaches();
precacheAndRoute(self.__WB_MANIFEST);

registerRoute(new NavigationRoute(new NetworkOnly()));

// Без сети вместо ошибки браузера переход показывает страницу «Нет сети».
setCatchHandler(async ({ request }) => {
    if (request.mode === 'navigate') {
        return (await matchPrecache(OFFLINE_PAGE)) ?? Response.error();
    }

    return Response.error();
});

// Пуш: WebPushMessage::toArray() из laravel-notification-channels/webpush.
type PushData = {
    // Куда ведёт нажатие на уведомление.
    url?: string;
    // Подписанные ссылки кнопок «Принял» и «Через 15 минут» в напоминании о приёме.
    take?: string;
    snooze?: string;
};

type PushPayload = {
    title: string;
    body?: string;
    icon?: string;
    badge?: string;
    tag?: string;
    renotify?: boolean;
    requireInteraction?: boolean;
    actions?: { action: string; title: string }[];
    data?: PushData;
};

// В типах TypeScript для уведомлений нет кнопок и повторного звука.
type ShownNotificationOptions = NotificationOptions & {
    actions?: { action: string; title: string }[];
    renotify?: boolean;
};

const APP_TITLE = 'Клинический компас';
const ICON = '/pwa-192x192.png';
const BADGE = '/notification-badge.png';

// Подтверждение «Приём отмечен» само исчезает через несколько секунд.
const CONFIRMATION_MS = 4000;

function readPayload(event: PushEvent): PushPayload {
    try {
        const payload = event.data?.json() as PushPayload | undefined;

        if (payload?.title) {
            return payload;
        }
    } catch {
        // Без разборчивого содержимого всё равно показываем уведомление, см. ниже.
    }

    return { title: APP_TITLE, data: { url: '/' } };
}

// Уведомление показывается на каждый пуш: Safari отзывает подписку, если пуш пришёл
// без уведомления, а Chrome показывает своё «сайт обновлён в фоне». Поэтому дубль
// с открытым чатом убирает сервер, а не service worker.
self.addEventListener('push', (event) => {
    const { title, ...options } = readPayload(event);

    event.waitUntil(
        self.registration.showNotification(title, {
            ...options,
            icon: options.icon ?? ICON,
            badge: options.badge ?? BADGE,
        } as ShownNotificationOptions),
    );
});

self.addEventListener('notificationclick', (event) => {
    const { notification, action } = event;
    const data = (notification.data ?? {}) as PushData;

    notification.close();

    if (action === 'take' || action === 'snooze') {
        event.waitUntil(runAction(action, data, notification.tag));

        return;
    }

    event.waitUntil(openPage(data.url ?? '/'));
});

/**
 * Кнопка в напоминании: запрос без открытия приложения, результат — коротким уведомлением.
 */
async function runAction(
    action: 'take' | 'snooze',
    data: PushData,
    tag: string,
): Promise<void> {
    let message: string | null = null;
    let reason: string | null = null;

    try {
        const response = await fetch(data[action] ?? '', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const result = (await response.json()) as { message?: string };

        if (response.ok && result.message) {
            message = result.message;
        } else {
            reason = result.message ?? null;
        }
    } catch {
        // Нет сети или ссылка устарела: пациенту предлагаем открыть приложение.
    }

    if (message !== null) {
        await self.registration.showNotification(message, {
            tag,
            icon: ICON,
            badge: BADGE,
            silent: true,
            data: { url: data.url },
        });
        await new Promise((resolve) => setTimeout(resolve, CONFIRMATION_MS));
        (await self.registration.getNotifications({ tag }))
            .filter((shown) => shown.title === message)
            .forEach((shown) => shown.close());

        return;
    }

    await self.registration.showNotification(
        action === 'take' ? 'Не получилось отметить' : 'Не получилось отложить',
        {
            tag,
            icon: ICON,
            badge: BADGE,
            body: `${reason ?? 'Нет связи с сервером.'} Откройте приложение, чтобы отметить приём.`,
            data: { url: data.url },
        },
    );
}

/**
 * Нажатие на уведомление: открытое приложение переходит на нужную страницу, иначе
 * открывается новое окно.
 */
async function openPage(url: string): Promise<void> {
    const target = new URL(url, self.location.origin).href;
    const windows = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });
    const open = windows.find(
        (client) => new URL(client.url).origin === self.location.origin,
    );

    if (open === undefined) {
        await self.clients.openWindow(target);

        return;
    }

    const focused = await open.focus();

    if (focused.url === target) {
        return;
    }

    try {
        await focused.navigate(target);
    } catch {
        // Окно, открытое до установки service worker, им не управляется и не переходит.
        await self.clients.openWindow(target);
    }
}
