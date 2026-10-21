<?php

use App\Actions\SendMessage;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Headless Chromium не подключён к сервису пушей и сразу запрещает уведомления. Разрешение
 * и подписку браузера подменяем до загрузки приложения: скрипт работает на всех следующих
 * страницах этой вкладки. Подписка — как у Chrome, у сервиса пушей Google — переживает
 * перезагрузку страницы.
 *
 * На запрос разрешения пользователь отвечает «Разрешить». Ответ на следующий запрос задаёт
 * window.answerPermissionPrompt('default'), а window.changePermission('denied') меняет
 * разрешение так, как это делает Chrome или настройки сайта.
 */
function fakeBrowserPush(mixed $page): mixed
{
    $page->page()->context()->addInitScript(<<<'JS'
        (() => {
            const endpoint = 'https://fcm.googleapis.com/fcm/send/browser-test';
            const subscribed = () => sessionStorage.getItem('fake-push') === 'on';
            let permission = subscribed() ? 'granted' : 'default';
            let answer = 'granted';
            const status = { onchange: null };
            const query = navigator.permissions.query.bind(navigator.permissions);
            const subscription = {
                endpoint,
                options: { applicationServerKey: null },
                toJSON: () => ({ endpoint, keys: { p256dh: 'p256dh-key', auth: 'auth-token' } }),
                unsubscribe: async () => {
                    sessionStorage.removeItem('fake-push');

                    return true;
                },
            };

            Object.defineProperty(Notification, 'permission', { configurable: true, get: () => permission });
            Notification.requestPermission = async () => {
                permission = answer;
                answer = 'granted';

                return permission;
            };
            navigator.permissions.query = async (descriptor) => (descriptor.name === 'notifications' ? status : query(descriptor));
            window.answerPermissionPrompt = (value) => {
                answer = value;
            };
            window.changePermission = (value) => {
                permission = value;

                if (value !== 'granted') {
                    sessionStorage.removeItem('fake-push');
                }

                status.onchange?.();
            };
            PushManager.prototype.subscribe = async (options) => {
                subscription.options.applicationServerKey = options.applicationServerKey.buffer;
                sessionStorage.setItem('fake-push', 'on');

                return subscription;
            };
            PushManager.prototype.getSubscription = async () => (subscribed() ? subscription : null);
        })();
        JS);

    return $page;
}

/**
 * Страница «Уведомления» и баннер ждут service worker: он регистрируется после загрузки.
 * Ждёт, пока элемент появится или, при $present = false, исчезнет.
 */
function waitForPushState(mixed $page, string $selector, bool $present = true): mixed
{
    return $page->assertScript(sprintf(<<<'JS'
        () => new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const done = (document.querySelector(%1$s) !== null) === %2$s;

                if (done || Date.now() - started > 10000) {
                    resolve(done);
                } else {
                    setTimeout(check, 100);
                }
            };

            check();
        })
        JS, json_encode($selector), $present ? 'true' : 'false'));
}

/**
 * Подписку страница отправляет в фоне: ждём, пока тестовый сервер её примет. Сервер работает
 * в этом же процессе, поэтому ждём через страницу, а не usleep.
 */
function waitForSubscriptions(mixed $page, User $user, int $count): void
{
    for ($attempt = 0; $attempt < 20 && $user->pushSubscriptions()->count() !== $count; $attempt++) {
        $page->wait(0.25);
    }

    expect($user->pushSubscriptions()->count())->toBe($count);
}

test('patient turns push on and off on the notifications page', function () {
    $patient = User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    fakeBrowserPush($page)
        ->navigate(route('notifications.edit'))
        ->assertSee('Напоминания о приёме и сообщения врача на этом устройстве');

    waitForPushState($page, '[data-test="push-enable"]')->click('[data-test="push-enable"]');

    waitForText($page, 'Уведомления включены на этом устройстве');
    expect($patient->pushSubscriptions()->sole()->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/browser-test');

    // Сервер подписку потерял (сервис пушей ответил 410): страница пришлёт её снова.
    $patient->pushSubscriptions()->delete();
    $page->navigate(route('notifications.edit'));
    waitForPushState($page, '[data-test="push-disable"]');
    waitForSubscriptions($page, $patient, 1);

    $page->click('[data-test="push-disable"]');
    waitForPushState($page, '[data-test="push-enable"]')->assertNoJavaScriptErrors();

    expect($patient->pushSubscriptions()->count())->toBe(0);
});

test('the notifications page follows the permission when the browser changes it', function () {
    $patient = User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    fakeBrowserPush($page)->navigate(route('notifications.edit'));
    waitForPushState($page, '[data-test="push-enable"]');

    // Тихий запрос Chrome на Android закрылся сам: разрешения нет, кнопка остаётся.
    $page->script('() => window.answerPermissionPrompt("default")');
    $page->click('[data-test="push-enable"]');
    waitForText($page, 'Браузер не получил разрешения')->assertPresent('[data-test="push-enable"]');

    // После нескольких закрытых запросов Chrome блокирует сайт сам.
    $page->script('() => window.changePermission("denied")');
    waitForPushState($page, '[data-test="push-denied"]')->assertDontSee('Браузер не получил разрешения');

    // Пользователь разрешил уведомления в настройках сайта: подписку создаёт кнопка.
    $page->script('() => window.changePermission("granted")');
    waitForPushState($page, '[data-test="push-enable"]')->click('[data-test="push-enable"]');
    waitForText($page, 'Уведомления включены на этом устройстве')->assertNoJavaScriptErrors();

    expect($patient->pushSubscriptions()->count())->toBe(1);
});

test('doctor turns push on from the banner and loses it on logout', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'smirnova']);

    $page = visit(route('login'))
        ->fill('login', 'smirnova')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients');

    fakeBrowserPush($page)->navigate(route('doctor.patients.index'));
    waitForPushState($page, '[data-test="push-banner"]')
        ->assertSee('Включите уведомления о сообщениях')
        ->click('[data-test="push-banner-enable"]');

    waitForPushState($page, '[data-test="push-banner"]', present: false);
    expect($doctor->pushSubscriptions()->count())->toBe(1);

    // На общем устройстве после выхода пуши врача сюда больше не приходят.
    $page->click('[data-test="sidebar-menu-button"]')
        ->click('Выйти')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();

    expect($doctor->pushSubscriptions()->count())->toBe(0);
});

test('the push banner can be put off and stays hidden', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    fakeBrowserPush($page)->navigate(route('patient.home'));
    waitForPushState($page, '[data-test="push-banner"]')
        ->assertSee('Включите напоминания')
        ->click('[data-test="push-banner-dismiss"]')
        ->assertMissing('[data-test="push-banner"]')
        ->navigate(route('patient.home'))
        ->wait(1)
        ->assertMissing('[data-test="push-banner"]')
        ->assertNoJavaScriptErrors();
});

test('a tap on a reminder brings the patient to the doses of the reminder', function () {
    // Время приложения только вперёд от настоящего (docs: cookie сессии).
    $tomorrow = CarbonImmutable::now('Europe/Moscow')->addDay()->setTime(7, 50);
    $this->travelTo($tomorrow);
    $patient = User::factory()->patient()->create(['login' => 'ivanov']);
    $attributes = ['starts_on' => $tomorrow->subWeek()->toDateString(), 'effective_at' => $tomorrow->subWeek()];
    timedPrescription($patient, catalogDrug('Эсциталопрам', [10 => true])->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], $attributes);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $evening = doseOn($patient, $tomorrow->toDateString(), 'evening');

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertMissing('[data-highlighted="true"]')
        // Экран Chrome на телефоне с Android: карточка помещается без прокрутки.
        ->resize(412, 656)
        ->navigate(route('patient.home', ['doses' => (string) $evening->id]))
        ->assertPresent('[data-test="dose-group"][data-highlighted="true"]')
        ->assertSeeIn('[data-test="dose-group"][data-highlighted="true"]', '20:00')
        ->assertScript('document.querySelector(\'[data-highlighted="true"]\').getBoundingClientRect().top < window.innerHeight');

    // Chrome предлагает установку уже после загрузки: баннер над карточками не уводит
    // выделенную карточку за край экрана.
    waitForPushState(offerInstall($page), '[data-test="install-banner"]')
        ->wait(0.5)
        ->assertScript(<<<'JS'
            () => {
                const card = document.querySelector('[data-highlighted="true"]').getBoundingClientRect();

                return card.top >= 0 && card.bottom <= window.innerHeight;
            }
            JS)
        ->assertNoJavaScriptErrors();
});

test('a message in a hidden chat tab stays unread until the tab is shown', function () {
    $reverb = startReverb();

    try {
        $doctor = User::factory()->doctor()->create();
        $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

        $page = visit(route('login'))
            ->on()->mobile()
            ->fill('login', 'ivanov')
            ->fill('password', 'password')
            ->press('Войти')
            ->assertPathIs('/patient')
            ->navigate(route('patient.chat.show'))
            ->assertSee('Чат не для экстренной помощи')
            // Тестовый сервер отвечает, только пока тест ждёт: даём странице подписаться на канал.
            ->wait(1);

        // Пациент свернул вкладку.
        $page->script(<<<'JS'
            () => {
                Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
                document.dispatchEvent(new Event('visibilitychange'));
            }
            JS);

        $message = app(SendMessage::class)->handle(careBetween($doctor, $patient), $doctor, 'Как вы спите?');
        waitForText($page, 'Как вы спите?')->wait(1);

        expect($message->refresh()->read_at)->toBeNull();

        $page->script(<<<'JS'
            () => {
                Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
                document.dispatchEvent(new Event('visibilitychange'));
            }
            JS);
        $page->wait(1)->assertNoJavaScriptErrors();

        expect($message->refresh()->read_at)->not->toBeNull();
    } finally {
        $reverb->stop();
    }
});

test('on an iPhone push waits for the app to be installed', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->withUserAgent(IPHONE_SAFARI)
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        // Про установку и напоминания говорит баннер установки, второго баннера нет.
        ->assertPresent('[data-test="install-banner"]')
        ->assertMissing('[data-test="push-banner"]')
        ->navigate(route('notifications.edit'));

    waitForText($page, 'На iPhone уведомления приходят только в установленное приложение')
        ->assertPresent('[data-test="install-ios-steps"]')
        ->assertMissing('[data-test="push-enable"]')
        ->assertNoJavaScriptErrors();
});

test('the push banner waits until the install banner is gone', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    fakeBrowserPush($page)->navigate(route('patient.home'));
    waitForPushState(offerInstall($page), '[data-test="install-banner"]')
        ->assertMissing('[data-test="push-banner"]')
        ->click('[data-test="install-dismiss"]');

    waitForPushState($page, '[data-test="push-banner"]')
        ->assertMissing('[data-test="install-banner"]')
        ->assertNoJavaScriptErrors();
});
