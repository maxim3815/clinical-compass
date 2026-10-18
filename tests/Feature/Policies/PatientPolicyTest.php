<?php

use App\Models\CareRelationship;
use App\Models\User;

test('doctor can view a patient with an active relationship', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();

    expect($doctor->can('view', $patient))->toBeTrue();
});

test('doctor can not view a patient after the relationship ended', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);

    expect($doctor->can('view', $patient))->toBeFalse();
});

test('doctor can not view patients of another doctor', function () {
    $doctor = User::factory()->doctor()->create();
    $doctor2 = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $patient4 = User::factory()->patientOf($doctor2)->create();

    expect($doctor2->can('view', $patient))->toBeFalse()
        ->and($doctor->can('view', $patient4))->toBeFalse()
        ->and($doctor2->can('view', $patient4))->toBeTrue();
});

test('patient can view only themselves', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $other = User::factory()->patientOf($doctor)->create();

    expect($patient->can('view', $patient))->toBeTrue()
        ->and($patient->can('view', $other))->toBeFalse();
});

test('a doctor is not viewed as a patient', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();

    expect($doctor->can('view', $doctor))->toBeFalse()
        ->and($patient->can('view', $doctor))->toBeFalse();
});
