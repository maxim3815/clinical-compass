<?php

use App\Actions\SendMessage;
use App\Models\User;

test('doctor finds a patient and gives a password reset link, the patient sets a new password', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич', 'login' => 'ivanov']);
    User::factory()->patientOf($doctor)->create(['name' => 'Кузнецова Елена Викторовна']);

    $page = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertSee('Кузнецова Елена Викторовна')
        ->fill('search', 'иВАНОВ')
        ->press('Найти')
        ->assertQueryStringHas('search', 'иВАНОВ')
        ->assertDontSee('Кузнецова Елена Викторовна')
        ->click('Иванов Пётр Алексеевич')
        ->assertPathIs('/doctor/patients/'.$patient->id)
        ->assertSee('ivanov')
        ->press('Ссылка для смены пароля')
        ->assertSee('Отправьте ссылку пациенту')
        ->assertNoJavaScriptErrors();

    $link = $page->value('[data-test="password-reset-link"] input');

    $page->click('[data-test="sidebar-menu-button"]')
        ->click('Выйти')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login')
        ->navigate($link)
        ->assertSee('Новый пароль')
        ->assertValue('#login', 'ivanov')
        ->fill('password', 'new-secret-password')
        ->fill('password_confirmation', 'new-secret-password')
        ->press('Сохранить пароль')
        ->assertPathIs('/patient')
        ->assertSee('Пароль изменён.')
        ->click('[data-test="tab-settings"]')
        ->click('[data-test="settings-logout"]')
        ->assertSee('Напоминания и уведомления на этом устройстве перестанут приходить')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login')
        ->fill('login', 'ivanov')
        ->fill('password', 'new-secret-password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertNoJavaScriptErrors();
});

test('doctor on a phone sees that the hidden menu has news', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create();
    app(SendMessage::class)->handle(careBetween($doctor, $patient), $patient, 'Добрый день! Можно вопрос?');

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertPresent('[data-test="menu-news"]')
        ->click('[data-sidebar="trigger"]')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('Сообщения')
        ->assertPathIs('/doctor/messages')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();
});
