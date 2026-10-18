<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

test('demo seeder creates a doctor and a patient', function () {
    $this->seed(DemoSeeder::class);

    $doctor = User::query()->where('login', 'doctor')->sole();
    $patient = User::query()->where('login', 'patient')->sole();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($patient->role)->toBe(UserRole::Patient)
        ->and(Hash::check('password', $doctor->password))->toBeTrue()
        ->and(Hash::check('password', $patient->password))->toBeTrue();
});

test('demo seeder can be run again', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::query()->count())->toBe(2);
});

test('database seeder adds demo users only in the local environment', function () {
    $this->seed();

    expect(User::query()->count())->toBe(0);
});
