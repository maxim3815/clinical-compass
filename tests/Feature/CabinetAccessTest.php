<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('doctor sees the doctor cabinet', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->followingRedirects()
        ->get(route('doctor.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('doctor/patients/Index'));
});

test('patient sees the patient cabinet', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('patient.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('patient/Home'));
});

test('patient can not open the doctor cabinet', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('doctor.home'))
        ->assertForbidden();
});

test('doctor can not open the patient cabinet', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('patient.home'))
        ->assertForbidden();
});

test('guest is sent to the login page from a cabinet', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['doctor.home', 'patient.home']);

test('shared user data has only public fields', function () {
    $doctor = User::factory()->doctor()->create();

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'login' => $doctor->login,
                'role' => 'doctor',
            ]),
        );
});
