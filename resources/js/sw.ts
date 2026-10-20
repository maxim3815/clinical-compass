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
