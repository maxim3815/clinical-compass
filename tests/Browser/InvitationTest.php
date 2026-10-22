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
        ->press('Пригласить по ссылке')
        ->assertSee('Отправьте ссылку пациенту')
        ->assertNoJavaScriptErrors();

    $link = $page->value('[data-test="invitation-link"] input');

    $page->click('[data-test="sidebar-menu-button"]')
        ->click('Выйти')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login')
        ->navigate($link)
        ->assertSee('Регистрация пациента')
        ->assertSee("Вас приглашает врач: {$doctor->name}, психиатр.")
        ->assertValue('name', 'Новиков Артём Сергеевич')
        ->fill('login', 'novikov')
        ->fill('password', 'secret-password')
        ->fill('password_confirmation', 'secret-password')
        // Сначала без согласия: ошибка с сервера, введённое не пропало.
        ->press('Зарегистрироваться')
        ->assertSee('Без согласия на обработку персональных данных зарегистрироваться нельзя.')
        ->assertAttribute('#consent', 'aria-invalid', 'true')
        ->assertValue('login', 'novikov')
        ->assertValue('password', 'secret-password')
        // Текст согласия открывается в окне на этой же странице, «Согласен» ставит галочку.
        ->click('[data-test="consent-text-button"]')
        ->assertSee('Регистрируясь, пациент соглашается на обработку своих персональных данных')
        ->click('[data-test="consent-agree"]')
        ->assertAttribute('#consent', 'aria-checked', 'true')
        ->press('Зарегистрироваться')
        ->assertPathIs('/patient')
        ->assertSee('Здравствуйте, Артём Сергеевич!')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'novikov')->sole();

    expect($patient->currentDoctor?->is($doctor))->toBeTrue();
});
