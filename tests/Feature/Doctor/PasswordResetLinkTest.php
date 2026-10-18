<?php

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

test('doctor issues a password reset link and sees it once', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();

    $this->actingAs($doctor)
        ->post(route('doctor.patients.password-reset-link.store', $patient))
        ->assertRedirect(route('doctor.patients.show', $patient))
        ->assertInertiaFlash('passwordResetUrl');

    $url = session(SessionKey::FLASH_DATA)['passwordResetUrl'];
    $token = basename($url);

    expect($url)->toStartWith(url('/password-reset/'))
        ->and(strlen($token))->toBe(40);

    $link = AccessLink::query()->sole();

    expect($link->purpose)->toBe(AccessLinkPurpose::PasswordReset)
        ->and($link->doctor_id)->toBe($doctor->id)
        ->and($link->patient_id)->toBe($patient->id)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and($link->expires_at->between(now()->addDay()->subMinute(), now()->addDay()))->toBeTrue();

    $this->get(route('doctor.patients.show', $patient))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('passwordResetUrl', $url));

    $this->get(route('doctor.patients.show', $patient))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('passwordResetUrl'));
});

test('new link revokes the previous unused links of the patient', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $previous = AccessLink::factory()->for($doctor, 'doctor')->create([
        'purpose' => AccessLinkPurpose::PasswordReset,
        'patient_id' => $patient->id,
    ]);
    $used = AccessLink::factory()->for($doctor, 'doctor')->create([
        'purpose' => AccessLinkPurpose::PasswordReset,
        'patient_id' => $patient->id,
        'used_at' => now()->subDay(),
    ]);
    $otherPatient = AccessLink::factory()->for($doctor, 'doctor')->create([
        'purpose' => AccessLinkPurpose::PasswordReset,
        'patient_id' => User::factory()->patientOf($doctor)->create()->id,
    ]);

    $this->actingAs($doctor)->post(route('doctor.patients.password-reset-link.store', $patient));

    expect($previous->refresh()->revoked_at)->not->toBeNull()
        ->and($used->refresh()->revoked_at)->toBeNull()
        ->and($otherPatient->refresh()->isActive())->toBeTrue()
        ->and(AccessLink::query()->where('patient_id', $patient->id)->get()->filter->isActive())->toHaveCount(1);
});

test('doctor can not issue a link for a patient they do not treat', function () {
    $patient = User::factory()->patientOf(User::factory()->doctor()->create())->create();

    $this->actingAs(User::factory()->doctor()->create())
        ->post(route('doctor.patients.password-reset-link.store', $patient))
        ->assertNotFound();

    expect(AccessLink::query()->exists())->toBeFalse();
});

test('patient can not issue a password reset link', function () {
    $patient = User::factory()->patientOf(User::factory()->doctor()->create())->create();

    $this->actingAs($patient)
        ->post(route('doctor.patients.password-reset-link.store', $patient))
        ->assertForbidden();
});
