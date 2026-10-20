<?php

use App\Models\User;

const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';

/**
 * Ждёт, пока service worker установится: предкэш идёт через тестовый сервер несколько секунд.
 */
function waitForServiceWorker(mixed $page): mixed
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $state = $page->script('() => navigator.serviceWorker.getRegistration().then((registration) => registration?.active?.state ?? null)');

        if ($state === 'activated') {
            return $page;
        }

        $page->wait(0.5);
    }

    throw new RuntimeException('Service worker не активировался за 10 секунд.');
}

/**
 * Chromium в тестах сам не предлагает установку: присылаем событие beforeinstallprompt,
 * как Chrome на Android. Пациент в системном диалоге соглашается.
 */
function offerInstall(mixed $page): mixed
{
    $page->script(<<<'JS'
        () => {
            const event = new Event('beforeinstallprompt', { cancelable: true });
            event.prompt = async () => {
                window.installDialogShown = true;
            };
            event.userChoice = Promise.resolve({ outcome: 'accepted' });
            window.dispatchEvent(event);
        }
        JS);

    return $page;
}

test('service worker covers the whole app and keeps the offline page', function () {
    $page = visit(route('login'))->assertSee('Войти');

    waitForServiceWorker($page)
        ->assertScript('navigator.serviceWorker.ready.then((registration) => new URL(registration.scope).pathname)', '/')
        ->assertScript('navigator.serviceWorker.ready.then((registration) => new URL(registration.active.scriptURL).pathname)', '/sw.js')
        ->assertScript('caches.match("/offline.html", { ignoreSearch: true }).then((response) => response !== undefined)')
        // В кэше только ассеты, иконки и страница «Нет сети»: страницы и ответы приложения в него не попадают.
        ->assertScript(<<<'JS'
            async () => {
                const urls = [];

                for (const name of await caches.keys()) {
                    for (const request of await (await caches.open(name)).keys()) {
                        urls.push(new URL(request.url).pathname);
                    }
                }

                return urls.length > 0 && urls.every((url) => url.startsWith('/build/assets/') || /^\/[\w-]+\.(html|png|svg|ico)$/.test(url));
            }
            JS)
        ->assertNoJavaScriptErrors();
});

test('patient on a phone installs the app from the banner', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertMissing('[data-test="install-banner"]');

    offerInstall($page)
        ->assertSee('Установите приложение')
        ->assertSee('Приложение откроется с экрана «Домой» одним нажатием.')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[data-test="install-button"]')
        ->assertMissing('[data-test="install-banner"]')
        ->assertScript('window.installDialogShown === true')
        ->assertNoJavaScriptErrors();
});

test('not now hides the install banner on this phone for a week', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    offerInstall($page)
        ->click('[data-test="install-dismiss"]')
        ->assertMissing('[data-test="install-banner"]')
        ->refresh();

    offerInstall($page)->assertMissing('[data-test="install-banner"]');

    $page->script('() => localStorage.setItem("install-banner-dismissed-at", String(Date.now() - 8 * 24 * 60 * 60 * 1000))');
    $page->refresh();

    offerInstall($page)
        ->assertVisible('[data-test="install-banner"]')
        ->assertNoJavaScriptErrors();
});

test('patient on an iphone sees how to add the app to the home screen', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    visit(route('login'))
        ->withUserAgent(IPHONE_SAFARI)
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Установите приложение')
        ->assertSee('на iPhone напоминания о приёме приходят только в установленное приложение')
        ->assertVisible('[data-test="install-ios-steps"]')
        ->assertSee('Выберите «На экран „Домой“»')
        ->assertMissing('[data-test="install-button"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('patient on a computer installs the app from settings, not from a banner', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    offerInstall($page)->assertMissing('[data-test="install-banner"]');

    $page->navigate(route('app.edit'))
        ->assertSee('Этот браузер не предлагает установить приложение');

    offerInstall($page)
        ->assertSee('Установите приложение: оно откроется с экрана «Домой»')
        ->click('[data-test="install-button"]')
        ->assertSee('Приложение установлено')
        ->assertNoJavaScriptErrors();
});

test('settings say the app is installed when it is opened from the home screen', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    // Safari на iPhone так сообщает, что страница открыта с экрана «Домой».
    $page = visit(route('login'))
        ->withUserAgent(IPHONE_SAFARI)
        ->on()->mobile()
        ->assertSee('Войти');

    $page->script('() => Object.defineProperty(navigator, "standalone", { value: true })');

    $page->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertMissing('[data-test="install-banner"]')
        ->click('[data-sidebar="trigger"]')
        ->click('Настройки')
        ->assertPathIs('/settings/profile')
        ->click('[href$="/settings/app"]')
        ->assertPathIs('/settings/app')
        ->assertVisible('[data-test="app-installed"]')
        ->assertNoJavaScriptErrors();
});

test('doctor sees neither the banner nor the app section of settings', function () {
    User::factory()->doctor()->create(['login' => 'smirnova']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'smirnova')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients');

    offerInstall($page)->assertMissing('[data-test="install-banner"]');

    $page->navigate(route('profile.edit'))
        ->assertSee('Профиль, пароль и уведомления')
        ->assertNotPresent('[href$="/settings/app"]')
        ->assertNoJavaScriptErrors();
});
