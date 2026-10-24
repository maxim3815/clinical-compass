<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('doctor password is reset and the new password works', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'volkov']);

    $this->artisan('app:doctor:reset-password', ['login' => 'Volkov'])
        ->expectsQuestion('Новый пароль', 'new-secret-password')
        ->expectsQuestion('Повторите пароль', 'new-secret-password')
        ->assertSuccessful();

    expect(Hash::check('new-secret-password', $doctor->refresh()->password))->toBeTrue();

    $this->post(route('login.store'), ['login' => 'volkov', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->post(route('login.store'), ['login' => 'volkov', 'password' => 'new-secret-password'])
        ->assertRedirect(route('doctor.home'));
});

test('doctor sessions are ended after the reset', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'volkov']);
    $rememberToken = $doctor->remember_token;
    DB::table('sessions')->insert([
        'id' => 'doctor-session',
        'user_id' => $doctor->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('app:doctor:reset-password', ['login' => 'volkov'])
        ->expectsQuestion('Новый пароль', 'new-secret-password')
        ->expectsQuestion('Повторите пароль', 'new-secret-password')
        ->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', $doctor->id)->exists())->toBeFalse()
        ->and($doctor->refresh()->remember_token)->not->toBe($rememberToken);
});

test('unknown login is reported', function () {
    $this->artisan('app:doctor:reset-password', ['login' => 'nobody'])
        ->assertFailed()
        ->expectsOutputToContain('Врач с логином «nobody» не найден.');
});

test('patient password can not be reset with this command', function () {
    User::factory()->patient()->create(['login' => 'patient']);

    $this->artisan('app:doctor:reset-password', ['login' => 'patient'])
        ->assertFailed()
        ->expectsOutputToContain('не найден');
});

test('short password is rejected', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'volkov']);

    $this->artisan('app:doctor:reset-password', ['login' => 'volkov'])
        ->expectsQuestion('Новый пароль', 'short')
        ->expectsQuestion('Повторите пароль', 'short')
        ->assertFailed()
        ->expectsOutputToContain('не меньше 8 символов');

    expect(Hash::check('password', $doctor->refresh()->password))->toBeTrue();
});
