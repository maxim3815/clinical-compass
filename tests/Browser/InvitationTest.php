<?php

use App\Models\User;

test('doctor invites a patient who registers by the link and sees the cabinet', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);

    $page = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Приглашения')
        ->assertPathIs('/doctor/invitations')
        ->fill('patient_name', 'Новиков Артём Сергеевич')
        ->press('Пригласить пациента')
        ->assertSee('Отправьте ссылку пациенту')
        ->assertNoJavaScriptErrors();

    $link = $page->value('[data-test="invitation-link"] input');

    $page->click('[data-test="sidebar-menu-button"]')
        ->click('Выйти')
        ->assertPathIs('/login')
        ->navigate($link)
        ->assertSee('Регистрация пациента')
        ->assertValue('name', 'Новиков Артём Сергеевич')
        ->fill('login', 'novikov')
        ->fill('password', 'secret-password')
        ->fill('password_confirmation', 'secret-password')
        ->click('#consent')
        ->press('Зарегистрироваться')
        ->assertPathIs('/patient')
        ->assertSee('Здравствуйте, Артём Сергеевич!')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'novikov')->sole();

    expect($patient->currentDoctor?->is($doctor))->toBeTrue();
});
