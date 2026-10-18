<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

test('password page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Password'));
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct current password must be provided', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors(['current_password' => 'Неверный пароль.'])
        ->assertRedirect(route('password.edit'));
});

test('new password must be at least 8 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors(['password' => 'Количество символов в поле пароль должно быть не меньше 8.']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
