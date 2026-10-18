<?php

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

const RESET_TOKEN = 'password-reset-token-for-tests-000000001';

function resetLinkFor(User $patient): AccessLink
{
    return AccessLink::factory()->withToken(RESET_TOKEN)->create([
        'purpose' => AccessLinkPurpose::PasswordReset,
        'doctor_id' => $patient->currentDoctor?->id,
        'patient_id' => $patient->id,
        'expires_at' => now()->addDay(),
    ]);
}

function treatedPatient(array $attributes = []): User
{
    return User::factory()->patientOf(User::factory()->doctor()->create())->create($attributes);
}

test('valid link shows the form with the patient login', function () {
    resetLinkFor(treatedPatient(['login' => 'ivanov']));

    $this->get(route('password-reset.show', RESET_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('password-reset/Reset')
            ->where('token', RESET_TOKEN)
            ->where('login', 'ivanov'),
        );
});

test('patient sets a new password by the link and signs in', function () {
    $patient = treatedPatient(['login' => 'ivanov']);
    $link = resetLinkFor($patient);

    $this->post(route('password-reset.store', RESET_TOKEN), [
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])
        ->assertRedirect(route('patient.home'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Пароль изменён.']);

    $this->assertAuthenticatedAs($patient);
    expect(Hash::check('new-secret-password', $patient->refresh()->password))->toBeTrue()
        ->and($link->refresh()->used_at)->not->toBeNull();

    $this->post(route('logout'));

    $this->post(route('login.store'), ['login' => 'ivanov', 'password' => 'password'])
        ->assertSessionHasErrors('login');
    $this->post(route('login.store'), ['login' => 'ivanov', 'password' => 'new-secret-password'])
        ->assertRedirect(route('patient.home'));
});

test('old sessions of the patient are ended', function () {
    $patient = treatedPatient();
    $rememberToken = $patient->remember_token;
    resetLinkFor($patient);
    DB::table('sessions')->insert([
        'id' => 'patient-phone-session',
        'user_id' => $patient->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->post(route('password-reset.store', RESET_TOKEN), [
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ]);

    expect(DB::table('sessions')->where('id', 'patient-phone-session')->exists())->toBeFalse()
        ->and($patient->refresh()->remember_token)->not->toBe($rememberToken);
});

test('link works only once', function () {
    resetLinkFor(treatedPatient());

    $data = ['password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password'];

    $this->post(route('password-reset.store', RESET_TOKEN), $data);
    $this->post(route('logout'));

    $this->post(route('password-reset.store', RESET_TOKEN), ['password' => 'third-password', 'password_confirmation' => 'third-password'])
        ->assertRedirect(route('password-reset.show', RESET_TOKEN));

    $this->assertGuest();
    $this->get(route('password-reset.show', RESET_TOKEN))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('access-link/Invalid'));
});

test('unusable links look the same as a missing one', function (Closure $makeLink) {
    $patient = treatedPatient();
    $makeLink($patient);
    $password = $patient->password;

    $this->get(route('password-reset.show', RESET_TOKEN))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('access-link/Invalid')
            ->missing('token')
            ->missing('login'),
        );

    $this->post(route('password-reset.store', RESET_TOKEN), ['password' => 'short'])
        ->assertRedirect(route('password-reset.show', RESET_TOKEN))
        ->assertSessionHasNoErrors();

    $this->assertGuest();
    expect($patient->refresh()->password)->toBe($password);
})->with([
    'missing' => fn () => null,
    'expired' => fn (User $patient) => resetLinkFor($patient)->update(['expires_at' => now()->subMinute()]),
    'revoked' => fn (User $patient) => resetLinkFor($patient)->update(['revoked_at' => now()]),
    'used' => fn (User $patient) => resetLinkFor($patient)->update(['used_at' => now()]),
    'invitation link' => fn (User $patient) => resetLinkFor($patient)->update(['purpose' => AccessLinkPurpose::Invitation]),
]);

test('new password is validated', function (array $data) {
    $patient = treatedPatient();
    $link = resetLinkFor($patient);
    $password = $patient->password;

    $this->post(route('password-reset.store', RESET_TOKEN), $data)
        ->assertSessionHasErrors('password');

    $this->assertGuest();
    expect($patient->refresh()->password)->toBe($password)
        ->and($link->refresh()->isActive())->toBeTrue();
})->with([
    'empty' => [['password' => '', 'password_confirmation' => '']],
    'short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'mismatch' => [['password' => 'new-secret-password', 'password_confirmation' => 'other-password']],
]);

test('signed in user is offered to log out instead of the form', function () {
    $patient = treatedPatient();
    $link = resetLinkFor($patient);

    $this->actingAs($patient)
        ->get(route('password-reset.show', RESET_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('access-link/SignedIn'));

    $this->post(route('password-reset.store', RESET_TOKEN), [
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertRedirect(route('password-reset.show', RESET_TOKEN));

    expect($link->refresh()->isActive())->toBeTrue();
});

test('password reset and invitation pages share the rate limit', function () {
    foreach (range(1, 5) as $attempt) {
        $this->get(route('password-reset.show', 'wrong-token-'.$attempt))->assertNotFound();
        $this->get(route('invitation.show', 'wrong-token-'.$attempt))->assertNotFound();
    }

    $this->get(route('password-reset.show', 'wrong-token-11'))->assertTooManyRequests();
});
