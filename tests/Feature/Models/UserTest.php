<?php

use App\Enums\UserRole;
use App\Models\User;

test('login is stored in lower case', function () {
    $user = User::factory()->create(['login' => 'Doctor.Smirnova']);

    expect($user->refresh()->login)->toBe('doctor.smirnova');
});

test('role is cast to the enum', function () {
    expect(User::factory()->doctor()->create()->refresh()->role)->toBe(UserRole::Doctor)
        ->and(User::factory()->patient()->create()->refresh()->role)->toBe(UserRole::Patient);
});
