<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\UserRole;
use App\Models\AccessLink;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

const INVITATION_TOKEN = 'invitation-token-for-tests-0000000000001';

function registrationData(array $overrides = []): array
{
    return [
        'name' => 'Новиков Артём Сергеевич',
        'login' => 'novikov',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'birth_date' => '1990-04-15',
        'consent' => '1',
        ...$overrides,
    ];
}

test('valid invitation shows the registration form with the name from the doctor', function () {
    AccessLink::factory()->withToken(INVITATION_TOKEN)->create(['patient_name' => 'Новиков Артём Сергеевич']);

    $this->get(route('invitation.show', INVITATION_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitation/Register')
            ->where('token', INVITATION_TOKEN)
            ->where('patientName', 'Новиков Артём Сергеевич'),
        );
});

test('patient registers by invitation and is attached to the doctor', function () {
    $doctor = User::factory()->doctor()->create();
    $invitation = AccessLink::factory()->for($doctor, 'doctor')->withToken(INVITATION_TOKEN)->create();

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData(['login' => 'Novikov']))
        ->assertRedirect(route('patient.home'));

    $patient = User::query()->where('login', 'novikov')->sole();

    $this->assertAuthenticatedAs($patient);
    expect($patient->role)->toBe(UserRole::Patient)
        ->and($patient->name)->toBe('Новиков Артём Сергеевич')
        ->and($patient->patientProfile?->birth_date?->toDateString())->toBe('1990-04-15')
        ->and($patient->patientProfile?->consented_at)->not->toBeNull()
        ->and($patient->currentDoctor?->is($doctor))->toBeTrue()
        ->and($invitation->refresh()->used_at)->not->toBeNull()
        ->and($invitation->patient_id)->toBe($patient->id);
});

test('birth date is optional', function () {
    AccessLink::factory()->withToken(INVITATION_TOKEN)->create();

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData(['birth_date' => null]))
        ->assertRedirect(route('patient.home'));

    expect(User::query()->where('login', 'novikov')->sole()->patientProfile?->birth_date)->toBeNull();
});

test('invitation works only once', function () {
    AccessLink::factory()->withToken(INVITATION_TOKEN)->create();

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData());
    $this->post(route('logout'));

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData(['login' => 'second']))
        ->assertRedirect(route('invitation.show', INVITATION_TOKEN))
        ->assertSessionHasNoErrors();

    $this->get(route('invitation.show', INVITATION_TOKEN))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('invitation/Invalid'));

    expect(User::query()->where('role', UserRole::Patient)->count())->toBe(1);
});

test('unusable invitations look the same as a missing one', function (Closure $makeInvitation) {
    $makeInvitation();
    $patients = User::query()->where('role', UserRole::Patient)->count();

    $this->get(route('invitation.show', INVITATION_TOKEN))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitation/Invalid')
            ->missing('token')
            ->missing('patientName'),
        );

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData(['login' => '']))
        ->assertRedirect(route('invitation.show', INVITATION_TOKEN))
        ->assertSessionHasNoErrors();

    expect(User::query()->where('role', UserRole::Patient)->count())->toBe($patients);
})->with([
    'missing' => fn () => null,
    'expired' => fn () => AccessLink::factory()->withToken(INVITATION_TOKEN)->expired()->create(),
    'revoked' => fn () => AccessLink::factory()->withToken(INVITATION_TOKEN)->revoked()->create(),
    'used' => fn () => AccessLink::factory()->withToken(INVITATION_TOKEN)->used()->create(),
    'password reset link' => fn () => AccessLink::factory()->withToken(INVITATION_TOKEN)->create([
        'purpose' => AccessLinkPurpose::PasswordReset,
    ]),
]);

test('registration requires consent', function () {
    AccessLink::factory()->withToken(INVITATION_TOKEN)->create();

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData(['consent' => null]))
        ->assertSessionHasErrors(['consent' => 'Без согласия на обработку персональных данных зарегистрироваться нельзя.']);

    $this->assertGuest();
    expect(User::query()->where('role', UserRole::Patient)->exists())->toBeFalse();
});

test('registration form is validated', function (array $data, string $field) {
    User::factory()->patient()->create(['login' => 'taken']);
    AccessLink::factory()->withToken(INVITATION_TOKEN)->create();

    $this->post(route('invitation.store', INVITATION_TOKEN), registrationData($data))
        ->assertSessionHasErrors($field);

    expect(AccessLink::query()->whereNotNull('used_at')->exists())->toBeFalse();
})->with([
    'empty name' => [['name' => ''], 'name'],
    'taken login' => [['login' => 'TAKEN'], 'login'],
    'login with cyrillic' => [['login' => 'новиков'], 'login'],
    'short login' => [['login' => 'ab'], 'login'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'password mismatch' => [['password_confirmation' => 'other-password'], 'password'],
    'birth date in the future' => [['birth_date' => now()->addDay()->toDateString()], 'birth_date'],
]);

test('signed in user is offered to log out instead of the form', function (bool $validLink) {
    if ($validLink) {
        AccessLink::factory()->withToken(INVITATION_TOKEN)->create();
    }

    $this->actingAs(User::factory()->patient()->create())
        ->get(route('invitation.show', INVITATION_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitation/SignedIn'));
})->with(['valid link' => true, 'invalid link' => false]);

test('signed in user can not register by invitation', function () {
    $invitation = AccessLink::factory()->withToken(INVITATION_TOKEN)->create();

    $this->actingAs(User::factory()->doctor()->create())
        ->post(route('invitation.store', INVITATION_TOKEN), registrationData())
        ->assertRedirect(route('invitation.show', INVITATION_TOKEN));

    expect($invitation->refresh()->used_at)->toBeNull()
        ->and(User::query()->where('login', 'novikov')->exists())->toBeFalse();
});

test('invitation pages are rate limited to 10 requests per minute', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get(route('invitation.show', 'wrong-token-'.$attempt))->assertNotFound();
    }

    $this->get(route('invitation.show', 'wrong-token-11'))->assertTooManyRequests();
});

test('consent page is public', function () {
    $this->get(route('consent'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Consent'));
});
