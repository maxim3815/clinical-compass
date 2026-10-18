<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

test('demo seeder creates two doctors with their patients', function () {
    $this->seed(DemoSeeder::class);

    $doctor = User::query()->where('login', 'doctor')->sole();
    $doctor2 = User::query()->where('login', 'doctor2')->sole();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->doctorProfile)->not->toBeNull()
        ->and($doctor->patients()->orderBy('login')->pluck('login')->all())->toBe(['patient', 'patient2', 'patient3'])
        ->and($doctor2->patients()->pluck('login')->all())->toBe(['patient4'])
        ->and(User::query()->where('role', UserRole::Patient)->whereDoesntHave('patientProfile')->exists())->toBeFalse()
        ->and(User::query()->get()->every(fn (User $user) => Hash::check('password', $user->password)))->toBeTrue();
});

test('demo data shows isolation between doctors', function () {
    $this->seed(DemoSeeder::class);

    $doctor2 = User::query()->where('login', 'doctor2')->sole();
    $patient = User::query()->where('login', 'patient')->sole();

    expect($doctor2->can('view', $patient))->toBeFalse();
});

test('demo seeder can be run again', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::query()->count())->toBe(6);
});

test('database seeder adds demo users only in the local environment', function () {
    $this->seed();

    expect(User::query()->count())->toBe(0);
});
