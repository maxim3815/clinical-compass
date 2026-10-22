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

test('doctor gets an own join code that is easy to dictate', function () {
    $first = app(CreateDoctor::class)->handle('Смирнова Анна Сергеевна', 'doctor.one', 'secret-password');
    $second = app(CreateDoctor::class)->handle('Волков Дмитрий Андреевич', 'doctor.two', 'secret-password');

    expect($first->doctorProfile?->join_code)->toMatch('/^[abcdefghjkmnpqrstuvwxyz23456789]{6}$/')
        ->and($second->doctorProfile?->join_code)->toMatch('/^[abcdefghjkmnpqrstuvwxyz23456789]{6}$/')
        ->and($first->doctorProfile?->join_code)->not->toBe($second->doctorProfile?->join_code);
});
