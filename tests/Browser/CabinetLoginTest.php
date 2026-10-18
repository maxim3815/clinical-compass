<?php

use App\Models\User;

test('doctor logs in and sees the doctor cabinet', function () {
    User::factory()->doctor()->create(['login' => 'doctor']);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertSee('Пациенты, которых вы сейчас ведёте.')
        ->assertNoJavaScriptErrors();
});

test('patient logs in and sees the patient cabinet', function () {
    User::factory()->patient()->create(['login' => 'patient']);

    visit(route('login'))
        ->fill('login', 'patient')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Кабинет пациента')
        ->assertNoJavaScriptErrors();
});
