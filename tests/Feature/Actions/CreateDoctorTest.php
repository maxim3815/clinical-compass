<?php

use App\Actions\CreateDoctor;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;

test('doctor is created with a profile', function () {
    $doctor = app(CreateDoctor::class)->handle('Смирнова Анна Сергеевна', 'Doctor.New', 'secret-password', 'Невролог');

    $doctor->refresh();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->login)->toBe('doctor.new')
        ->and(Hash::check('secret-password', $doctor->password))->toBeTrue()
        ->and($doctor->doctorProfile?->specialty)->toBe('Невролог');
});

test('specialty is psychiatrist by default', function () {
    $doctor = app(CreateDoctor::class)->handle('Смирнова Анна Сергеевна', 'doctor.new', 'secret-password');

    expect($doctor->doctorProfile?->specialty)->toBe('Психиатр');
});
