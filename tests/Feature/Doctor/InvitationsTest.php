<?php

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

test('doctor sees only own invitations', function () {
    $doctor = User::factory()->doctor()->create();
    AccessLink::factory()->for($doctor, 'doctor')->create(['patient_name' => 'Новиков Артём Сергеевич']);
    AccessLink::factory()->create(['patient_name' => 'Чужой пациент']);

    $this->actingAs($doctor)
        ->get(route('doctor.invitations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/Invitations')
            ->has('invitations', 1)
            ->where('invitations.0.patient_name', 'Новиков Артём Сергеевич')
            ->where('invitations.0.status', 'active')
            ->where('invitations.0.can_revoke', true),
        );
});

test('patient can not open invitations', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('doctor.invitations.index'))
        ->assertForbidden();
});

test('doctor creates an invitation and sees the link once', function () {
    $doctor = User::factory()->doctor()->create();

    $response = $this->actingAs($doctor)
        ->post(route('doctor.invitations.store'), ['patient_name' => 'Новиков Артём Сергеевич'])
        ->assertRedirect(route('doctor.invitations.index'))
        ->assertInertiaFlash('invitationUrl');

    $url = session(SessionKey::FLASH_DATA)['invitationUrl'];
    $token = basename($url);

    expect($url)->toStartWith(url('/invite/'))
        ->and(strlen($token))->toBe(40);

    $invitation = AccessLink::query()->sole();

    expect($invitation->purpose)->toBe(AccessLinkPurpose::Invitation)
        ->and($invitation->doctor_id)->toBe($doctor->id)
        ->and($invitation->patient_name)->toBe('Новиков Артём Сергеевич')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)))->toBeTrue();

    $this->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('invitationUrl', $url));

    $this->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('invitationUrl'));
});

test('patient name is optional', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->post(route('doctor.invitations.store'))
        ->assertSessionHasNoErrors();

    expect(AccessLink::query()->sole()->patient_name)->toBeNull();
});

test('invitation statuses are shown', function () {
    $doctor = User::factory()->doctor()->create();
    AccessLink::factory()->for($doctor, 'doctor')->used()->create();
    AccessLink::factory()->for($doctor, 'doctor')->expired()->create();
    AccessLink::factory()->for($doctor, 'doctor')->revoked()->create();

    $this->actingAs($doctor)
        ->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitations.0.status_label', 'Отозвано')
            ->where('invitations.1.status_label', 'Истекло')
            ->where('invitations.2.status_label', 'Использовано')
            ->where('invitations.0.can_revoke', false)
            ->where('invitations.1.can_revoke', false)
            ->where('invitations.2.can_revoke', false),
        );
});

test('used invitation shows the registered patient name', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    AccessLink::factory()->for($doctor, 'doctor')->create([
        'patient_name' => null,
        'patient_id' => $patient->id,
        'used_at' => now(),
    ]);

    $this->actingAs($doctor)
        ->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('invitations.0.patient_name', 'Иванов Пётр Алексеевич'));
});

test('doctor revokes own active invitation', function () {
    $doctor = User::factory()->doctor()->create();
    $invitation = AccessLink::factory()->for($doctor, 'doctor')->create();

    $this->actingAs($doctor)
        ->post(route('doctor.invitations.revoke', $invitation))
        ->assertRedirect(route('doctor.invitations.index'));

    expect($invitation->refresh()->revoked_at)->not->toBeNull();
});

test('doctor can not revoke an invitation of another doctor', function () {
    $invitation = AccessLink::factory()->create();

    $this->actingAs(User::factory()->doctor()->create())
        ->post(route('doctor.invitations.revoke', $invitation))
        ->assertForbidden();

    expect($invitation->refresh()->revoked_at)->toBeNull();
});

test('used invitation stays used after revoke', function () {
    $doctor = User::factory()->doctor()->create();
    $invitation = AccessLink::factory()->for($doctor, 'doctor')->used()->create();

    $this->actingAs($doctor)->post(route('doctor.invitations.revoke', $invitation));

    expect($invitation->refresh()->revoked_at)->toBeNull();
});
