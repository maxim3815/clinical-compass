<?php

use App\Enums\UserRole;
use App\Models\User;

test('doctor is created from options and can log in', function () {
    $this->artisan('app:doctor:create', [
        '--name' => 'Волков Дмитрий Андреевич',
        '--login' => 'Volkov',
        '--password' => 'secret-password',
    ])->assertSuccessful()
        ->expectsOutputToContain('логин volkov');

    $doctor = User::query()->where('login', 'volkov')->sole();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->doctorProfile?->specialty)->toBe('Психиатр');

    $this->post(route('login.store'), ['login' => 'volkov', 'password' => 'secret-password'])
        ->assertRedirect(route('doctor.home'));
});

test('doctor is created from prompts', function () {
    $this->artisan('app:doctor:create', ['--specialty' => 'Невролог'])
        ->expectsQuestion('ФИО', 'Волков Дмитрий Андреевич')
        ->expectsQuestion('Логин', 'volkov')
        ->expectsQuestion('Пароль', 'secret-password')
        ->expectsQuestion('Повторите пароль', 'secret-password')
        ->assertSuccessful();

    expect(User::query()->where('login', 'volkov')->sole()->doctorProfile?->specialty)->toBe('Невролог');
});

test('taken login is rejected with a clear error', function () {
    User::factory()->doctor()->create(['login' => 'volkov']);

    $this->artisan('app:doctor:create', [
        '--name' => 'Волков Дмитрий Андреевич',
        '--login' => 'VOLKOV',
        '--password' => 'secret-password',
    ])->assertFailed()
        ->expectsOutputToContain('Такой логин уже занят.');

    expect(User::query()->count())->toBe(1);
});

test('invalid input is rejected', function () {
    $this->artisan('app:doctor:create', [
        '--name' => 'Волков Дмитрий Андреевич',
        '--login' => 'волков',
        '--password' => 'short',
    ])->assertFailed()
        ->expectsOutputToContain('Логин может состоять только из латинских букв')
        ->expectsOutputToContain('не меньше 8');

    expect(User::query()->count())->toBe(0);
});

test('password confirmation must match', function () {
    $this->artisan('app:doctor:create', ['--name' => 'Волков Дмитрий Андреевич', '--login' => 'volkov'])
        ->expectsQuestion('Пароль', 'secret-password')
        ->expectsQuestion('Повторите пароль', 'other-password')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
