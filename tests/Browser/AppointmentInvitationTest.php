<?php

use App\Models\AccessLink;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->doctor->doctorProfile?->update(['join_code' => 'smirnova']);
});

afterEach(fn () => $this->reverb->stop());

test('doctor gives a pin at the appointment, the patient enters it on the phone and registers, the doctor sees it live', function () {
    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Приглашения')
        ->assertPathIs('/doctor/invitations')
        ->press('Пригласить на приёме')
        ->assertSee('Код для пациента')
        ->assertSee('Действует ещё 15:00')
        // Первым в фокусе — «Закрыть», а не ссылка, которая уводит со страницы.
        ->assertScript('document.activeElement?.textContent.trim()', 'Закрыть')
        ->assertSeeIn('[data-test="appointment-address"]', '/join/smirnova')
        ->assertPresent('[data-test="appointment-qr"] svg')
        ->assertNoJavaScriptErrors();

    $pin = trim((string) $doctorPage->text('[data-test="appointment-pin"] [aria-hidden="true"]'));

    expect($pin)->toMatch('/^\d{4}$/');

    $patientPage = visit(route('join.show', 'smirnova'))
        ->on()->mobile()
        ->assertSee('Подключение к врачу')
        ->assertSee('Врач: Смирнова Анна Сергеевна, психиатр.')
        ->assertAttribute('[aria-label="Цифра 1 из 4"]', 'inputmode', 'numeric')
        // Нажатие на клетку правее пустой ведёт в первую пустую: цифры идут по порядку.
        ->click('[aria-label="Цифра 3 из 4"]')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Цифра 1 из 4')
        // Подсказки кодов из SMS над клавиатурой не нужны: это не код из сообщения.
        ->assertAttribute('[aria-label="Цифра 1 из 4"]', 'autocomplete', 'off')
        // Неверный код: ошибка, ячейки пустые, фокус в первой — можно набирать снова.
        ->typeSlowly('[aria-label="Цифра 1 из 4"]', wrongPin($pin), 30)
        ->assertSee('Код не подошёл.')
        ->assertValue('[aria-label="Цифра 1 из 4"]', '')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Цифра 1 из 4')
        // Четвёртая цифра сама отправляет код: кнопки нет.
        ->typeSlowly('[aria-label="Цифра 1 из 4"]', $pin, 30)
        ->assertPathBeginsWith('/invite/')
        ->assertSee('Вас приглашает врач: Смирнова Анна Сергеевна, психиатр.')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    waitForText($doctorPage, 'Код введён, пациент заполняет форму.');

    $patientPage->fill('name', 'Кузьмин Олег Викторович')
        ->fill('login', 'kuzmin')
        ->fill('password', 'secret-password')
        ->fill('password_confirmation', 'secret-password')
        ->click('[data-test="consent-text-button"]')
        ->click('[data-test="consent-agree"]')
        ->press('Зарегистрироваться')
        ->assertPathIs('/patient')
        ->assertNoJavaScriptErrors();

    waitForText($doctorPage, 'Регистрация завершена: Кузьмин Олег Викторович.')
        // Приглашение появилось в списке под окном без перезагрузки.
        ->assertSeeIn('[data-test="invitation-row"]', 'Кузьмин Олег Викторович')
        ->click('[data-test="appointment-open-patient"]')
        ->assertPathBeginsWith('/doctor/patients/')
        ->assertSee('Кузьмин Олег Викторович')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'kuzmin')->sole();

    expect($patient->currentDoctor?->is($this->doctor))->toBeTrue();
});

test('desk card prints the doctor address with the qr code', function () {
    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('Приглашения')
        ->click('Карточка для стола')
        ->assertPathIs('/doctor/invitations/card')
        ->assertSeeIn('[data-test="join-card"]', 'Подключение к врачу')
        ->assertSeeIn('[data-test="join-card"]', 'Смирнова Анна Сергеевна, психиатр')
        ->assertSeeIn('[data-test="join-card"]', '/join/smirnova')
        ->assertPresent('[data-test="join-card"] svg')
        ->assertNoJavaScriptErrors();
});

test('code the doctor already named survives closing the window, a new one is issued on request', function () {
    $page = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->press('Пригласить на приёме')
        ->assertSee('Код для пациента');

    $pin = trim((string) $page->text('[data-test="appointment-pin"] [aria-hidden="true"]'));

    // Открыли снова, пока код действует: тот же код, новой записи нет.
    $page->press('Закрыть')
        ->assertMissing('[data-test="appointment-invitation-dialog"]')
        ->press('Пригласить на приёме')
        ->assertSeeIn('[data-test="appointment-pin"]', $pin)
        // И на другой странице кабинета.
        ->press('Закрыть')
        ->click('Приглашения')
        ->assertPathIs('/doctor/invitations')
        ->press('Пригласить на приёме')
        ->assertSeeIn('[data-test="appointment-pin"]', $pin);

    expect(AccessLink::query()->count())->toBe(1);

    $page->click('[data-test="appointment-new-pin"]')
        ->assertSee('Действует ещё 15:00')
        ->assertNoJavaScriptErrors();

    expect(AccessLink::query()->count())->toBe(2)
        ->and(AccessLink::query()->awaitingPin()->count())->toBe(1);
});
