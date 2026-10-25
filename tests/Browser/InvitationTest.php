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
        ->assertSee('Поставьте галочку «Даю согласие», чтобы зарегистрироваться.')
        ->assertAttribute('#consent', 'aria-invalid', 'true')
        ->assertValue('login', 'novikov')
        ->assertValue('password', 'secret-password')
        // Текст согласия открывается в окне на этой же странице, «Даю согласие» ставит галочку.
        ->click('[data-test="consent-text-button"]')
        ->assertSee('Регистрируясь, вы соглашаетесь, что ваши персональные данные')
        ->click('[data-test="consent-agree"]')
        ->assertAttribute('#consent', 'aria-checked', 'true')
        ->press('Зарегистрироваться')
        // Сразу после регистрации — правила: вкладок нет, кнопка помощи в шапке есть.
        ->assertPathIs('/patient/rules')
        ->assertSee('Как работает помощь в приложении')
        ->assertMissing('nav[aria-label="Разделы"]')
        ->assertPresent('[data-test="help-button"]')
        ->click('[data-test="rules-acknowledge"]')
        ->assertPathIs('/patient')
        ->assertSee('Здравствуйте, Артём Сергеевич!')
        ->assertNoJavaScriptErrors();

    // Правила и согласие перечитываются из настроек, не выходя из кабинета, правила — и с экрана помощи.
    $page->navigate(route('profile.edit'))
        ->click('Как работает помощь в приложении')
        ->assertPathIs('/patient/rules')
        ->assertMissing('[data-test="rules-acknowledge"]')
        ->assertPresent('nav[aria-label="Разделы"]')
        ->click('[data-test="back-link"]')
        ->assertPathIs('/settings/profile')
        ->click('Согласие на обработку персональных данных')
        ->assertPathIs('/patient/consent')
        ->assertSeeIn('[data-test="consent-signed"]', 'Вы дали это согласие при регистрации')
        ->assertSee('Кто отвечает за данные')
        ->click('[data-test="help-button"]')
        ->click('[data-test="help-rules-link"]')
        ->assertPathIs('/patient/rules')
        ->assertSeeIn('[data-test="back-link"]', 'Помощь')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'novikov')->sole();

    expect($patient->currentDoctor?->is($doctor))->toBeTrue();
});
