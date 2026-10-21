<?php

use App\Models\User;

test('pages stay light when the phone has the dark theme', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    $light = "!document.documentElement.classList.contains('dark') && getComputedStyle(document.body).backgroundColor === 'rgb(251, 249, 245)'";

    visit(route('login'))
        ->inDarkMode()
        ->on()->mobile()
        ->assertScript("matchMedia('(prefers-color-scheme: dark)').matches")
        ->assertScript($light)
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertScript($light)
        ->click('[data-test="tab-settings"]')
        ->assertPathIs('/settings/profile')
        ->assertSeeIn('nav[aria-label="Настройки"]', 'Приложение')
        ->assertDontSee('Оформление')
        ->assertScript($light)
        ->assertNoJavaScriptErrors();
});
