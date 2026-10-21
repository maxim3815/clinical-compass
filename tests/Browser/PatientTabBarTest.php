<?php

use App\Actions\SendMessage;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
});

afterEach(fn () => $this->reverb->stop());

// Конец страницы виден над панелью вкладок: после прокрутки вниз последний блок выше панели.
const ABOVE_TAB_BAR = <<<'JS'
    (() => {
        window.scrollTo(0, document.documentElement.scrollHeight);
        const content = document.querySelector('main').lastElementChild.getBoundingClientRect();
        const bar = document.querySelector('[data-test="tab-bar"]').getBoundingClientRect();

        return content.bottom <= bar.top;
    })()
    JS;

// В чате поле и кнопка «Отправить» видны сразу, панель вкладок их не закрывает.
const SEND_ABOVE_TAB_BAR = <<<'JS'
    (() => {
        const send = [...document.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Отправить');
        const bar = document.querySelector('[data-test="tab-bar"]').getBoundingClientRect();

        return send.getBoundingClientRect().bottom <= bar.top;
    })()
    JS;

test('patient moves between sections with the tab bar on a phone', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        // Бокового меню и «гамбургера» у пациента нет.
        ->assertMissing('[data-sidebar="trigger"]')
        ->assertAttribute('[data-test="tab-today"]', 'aria-current', 'page')
        ->click('[data-test="tab-medications"]')
        ->assertPathIs('/patient/medications')
        ->assertAttribute('[data-test="tab-medications"]', 'aria-current', 'page')
        ->assertAttributeMissing('[data-test="tab-today"]', 'aria-current')
        // На форме панели вкладок нет: случайное касание не уносит введённое. Назад ведёт шапка.
        ->click('[data-test="add-medication-button"]')
        ->assertPathIs('/patient/medications/create')
        ->assertMissing('[data-test="tab-bar"]')
        ->assertSeeIn('[data-test="back-link"]', 'Мои препараты')
        ->assertScript(FITS_SCREEN)
        ->click('[data-test="back-link"]')
        ->assertPathIs('/patient/medications')
        ->click('[data-test="tab-chat"]')
        ->assertPathIs('/patient/chat')
        ->assertAttribute('[data-test="tab-chat"]', 'aria-current', 'page')
        ->assertScript(SEND_ABOVE_TAB_BAR)
        ->click('[data-test="tab-settings"]')
        ->assertPathIs('/settings/profile')
        ->click('[href$="/settings/password"]')
        ->assertPathIs('/settings/password')
        ->assertAttribute('[data-test="tab-settings"]', 'aria-current', 'page')
        ->assertScript(ABOVE_TAB_BAR)
        ->click('[data-test="tab-today"]')
        ->assertPathIs('/patient')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();
});

test('chat tab counts unread messages from the doctor without reloading the page', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertMissing('[data-test="nav-badge"]')
        // Тестовый сервер отвечает, только пока тест ждёт: даём странице подписаться на канал.
        ->wait(1);

    app(SendMessage::class)->handle(careBetween($doctor, $patient), $doctor, 'Как вы спали этой ночью?');

    waitForText($page, 'непрочитанных: 1')
        ->assertSeeIn('[data-test="tab-chat"] [data-test="nav-badge"]', '1')
        ->click('[data-test="tab-chat"]')
        ->assertPathIs('/patient/chat')
        ->assertSee('Как вы спали этой ночью?');

    // Открытый чат прочитал сообщение, счётчик ушёл.
    waitForText($page, 'Отправить')
        ->assertScript("document.querySelector('[data-test=\"nav-badge\"]') === null")
        ->assertNoJavaScriptErrors();
});

test('patient signs out from the settings', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="tab-settings"]')
        ->click('[data-test="settings-logout"]')
        ->assertSee('Напоминания и уведомления на этом устройстве перестанут приходить')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});
