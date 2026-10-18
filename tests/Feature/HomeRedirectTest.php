<?php

use App\Models\User;

test('guest is sent from home to the login page', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/', '/dashboard']);

test('doctor is sent from home to the doctor cabinet', function (string $path) {
    $this->actingAs(User::factory()->doctor()->create())
        ->get($path)
        ->assertRedirect(route('doctor.home'));
})->with(['/', '/dashboard']);

test('patient is sent from home to the patient cabinet', function (string $path) {
    $this->actingAs(User::factory()->patient()->create())
        ->get($path)
        ->assertRedirect(route('patient.home'));
})->with(['/', '/dashboard']);

test('signed in user is sent from the login page through the dashboard', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});
