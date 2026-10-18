<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Profile'));
});

test('name can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Петрова Мария Ивановна',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->name)->toBe('Петрова Мария Ивановна');
});

test('login can not be changed', function () {
    $user = User::factory()->create(['login' => 'patient.ivanov']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'login' => 'someone.else',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->login)->toBe('patient.ivanov');
});

test('name is required and the error is in russian', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Поле ФИО обязательно.'])
        ->assertRedirect(route('profile.edit'));
});

test('appearance page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Appearance'));
});
