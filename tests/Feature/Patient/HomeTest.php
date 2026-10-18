<?php

use App\Models\CareRelationship;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('patient sees the name and specialty of their doctor', function () {
    $doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $doctor->doctorProfile?->update(['specialty' => 'Психиатр']);

    $this->actingAs(User::factory()->patientOf($doctor)->create())
        ->get(route('patient.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Home')
            ->where('doctor', ['name' => 'Смирнова Анна Сергеевна', 'specialty' => 'Психиатр']),
        );
});

test('patient without an active relationship has no doctor', function () {
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['patient_id' => $patient->id]);

    $this->actingAs($patient)
        ->get(route('patient.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('doctor', null));
});

test('patient sees the current doctor after changing doctors', function () {
    $former = User::factory()->doctor()->create(['name' => 'Волков Дмитрий Андреевич']);
    $current = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['doctor_id' => $former->id, 'patient_id' => $patient->id]);
    CareRelationship::factory()->create(['doctor_id' => $current->id, 'patient_id' => $patient->id]);

    $this->actingAs($patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('doctor.name', 'Смирнова Анна Сергеевна'));
});
