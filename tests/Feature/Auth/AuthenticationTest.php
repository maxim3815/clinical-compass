<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('login screen can be rendered', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

test('doctor is redirected to the doctor cabinet after login', function () {
    $doctor = User::factory()->doctor()->create();

    $response = $this->post(route('login.store'), [
        'login' => $doctor->login,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($doctor);
    $response->assertRedirect(route('doctor.home'));
});

test('patient is redirected to the patient cabinet after login', function () {
    $patient = User::factory()->patient()->create();

    $response = $this->post(route('login.store'), [
        'login' => $patient->login,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($patient);
    $response->assertRedirect(route('patient.home'));
});

test('login is accepted in any case', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'doctor.smirnova']);

    $this->post(route('login.store'), [
        'login' => 'Doctor.SMIRNOVA',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($doctor);
});

test('wrong password gives an error in russian', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'login' => $user->login,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['login' => 'Неверный логин или пароль.']);

    $this->assertGuest();
});

test('login is rate limited after five attempts per minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), [
            'login' => $user->login,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('login');
    }

    $this->post(route('login.store'), [
        'login' => $user->login,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
