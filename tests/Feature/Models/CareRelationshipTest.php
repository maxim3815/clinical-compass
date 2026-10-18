<?php

use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('patient can not have a second active relationship', function () {
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->create(['patient_id' => $patient->id]);

    expect(fn () => CareRelationship::factory()->create(['patient_id' => $patient->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('active pair can not be duplicated', function () {
    $relationship = CareRelationship::factory()->create();

    expect(fn () => CareRelationship::factory()->create([
        'doctor_id' => $relationship->doctor_id,
        'patient_id' => $relationship->patient_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('patient can get a new doctor after the previous relationship ended', function () {
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['patient_id' => $patient->id]);
    $newDoctor = User::factory()->doctor()->create();

    CareRelationship::factory()->create(['doctor_id' => $newDoctor->id, 'patient_id' => $patient->id]);

    expect($patient->currentDoctor?->is($newDoctor))->toBeTrue()
        ->and(CareRelationship::query()->where('patient_id', $patient->id)->count())->toBe(2);
});

test('relationship can not end before it started', function () {
    expect(fn () => CareRelationship::factory()->create([
        'started_at' => now(),
        'ended_at' => now()->subDay(),
    ]))->toThrow(QueryException::class);
});

test('user can not be their own doctor', function () {
    $user = User::factory()->doctor()->create();

    expect(fn () => CareRelationship::factory()->create(['doctor_id' => $user->id, 'patient_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('doctor patients include only active relationships', function () {
    $doctor = User::factory()->doctor()->create();
    $current = User::factory()->patientOf($doctor)->create();
    $former = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['doctor_id' => $doctor->id, 'patient_id' => $former->id]);
    User::factory()->patientOf(User::factory()->doctor()->create())->create();

    expect($doctor->patients()->pluck('users.id')->all())->toBe([$current->id]);
});

test('patient current doctor is the doctor of the active relationship', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();

    expect($patient->currentDoctor?->is($doctor))->toBeTrue();

    CareRelationship::query()->where('patient_id', $patient->id)->update(['ended_at' => now()]);

    expect($patient->fresh()?->currentDoctor)->toBeNull();
});

test('factory creates the profile of the role', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patient()->create();

    expect($doctor->doctorProfile?->specialty)->toBe('Психиатр')
        ->and($doctor->patientProfile)->toBeNull()
        ->and($patient->patientProfile)->not->toBeNull()
        ->and($patient->doctorProfile)->toBeNull();
});
