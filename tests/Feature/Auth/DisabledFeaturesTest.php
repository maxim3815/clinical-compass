<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('routes of disabled features are not registered', function (string $name) {
    expect(Route::has($name))->toBeFalse();
})->with([
    'register',
    'password.request',
    'password.reset',
    'password.email',
    'password.update',
    'verification.notice',
    'verification.verify',
    'verification.send',
    'two-factor.login',
    'two-factor.enable',
    'passkey.login',
    'passkey.login-options',
    'passkeys.store',
    'well-known.passkeys',
    'profile.destroy',
    'security.edit',
]);

test('no route serves registration, email, two factor or passkeys', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect($uris->filter(fn (string $uri) => preg_match('/register|forgot-password|reset-password|email|two-factor|passkey/', $uri)))
        ->toBeEmpty();
});

test('account can not be deleted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    expect($user->fresh())->not->toBeNull();
});
