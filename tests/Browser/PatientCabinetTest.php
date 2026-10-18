<?php

use App\Enums\RussianTimezone;
use App\Models\User;

const FITS_SCREEN = 'document.documentElement.scrollWidth <= window.innerWidth';

test('patient on a phone sees the doctor and changes the timezone', function () {
    $doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич', 'login' => 'ivanov']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Здравствуйте, Пётр Алексеевич!')
        ->assertSee('Смирнова Анна Сергеевна')
        ->assertSee('Психиатр')
        ->assertScript(FITS_SCREEN)
        ->click('[data-sidebar="trigger"]')
        ->click('Настройки')
        ->assertPathIs('/settings/profile')
        ->assertScript(FITS_SCREEN)
        ->select('timezone', 'Asia/Yekaterinburg')
        ->press('Сохранить')
        ->assertSee('Профиль обновлён.')
        ->assertNoJavaScriptErrors();

    expect($patient->refresh()->patientProfile?->timezone)->toBe(RussianTimezone::Yekaterinburg);
});

test('patient without a doctor sees what to do', function () {
    User::factory()->patient()->create(['login' => 'alone']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'alone')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Вы сейчас не прикреплены к врачу')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();
});
