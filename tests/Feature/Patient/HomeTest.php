<?php

use App\Enums\PrescriptionCloseReason;
use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
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

test('patient is asked to choose the time for prescriptions with intakes without time', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
    $aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
    $sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();

    $withoutTime = regularPrescription($patient, $hydroxyzine, ['evening' => '1'], ['starts_on' => now()->subWeek()->toDateString()]);
    $upcoming = regularPrescription($patient, $aviandr, ['morning' => '1', 'evening' => '1'], ['starts_on' => now()->addDays(3)->toDateString()]);
    // Новый приём после изменения назначения ждёт времени, хотя у остальных оно есть.
    $partly = timedPrescription($patient, $sertraline, ['morning' => '08:00'], ['ends_on' => now()->addMonth()->toDateString()]);
    PrescriptionIntake::factory()->for($partly)->create(['time_of_day' => 'bedtime']);

    // Всё время выбрано, «по необходимости», закрытые и завершённые карточек не дают.
    timedPrescription($patient, $sertraline, ['morning' => '08:00'], ['starts_on' => now()->addMonths(2)->toDateString()]);
    Prescription::factory()->for($patient, 'patient')->for($hydroxyzine, 'strength')->asNeeded()->create();
    regularPrescription($patient, $hydroxyzine, attributes: ['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);
    regularPrescription($patient, $hydroxyzine, attributes: ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
    ownPrescription($patient, $aviandr->drug, attributes: ['starts_on' => now()->addMonths(3)->toDateString()]);

    $this->actingAs($patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('timeSetup', [
            ['id' => $upcoming->id, 'title' => 'Авиандр, 20 мг'],
            ['id' => $withoutTime->id, 'title' => 'Гидроксизин, 25 мг'],
            ['id' => $partly->id, 'title' => 'Сертралин, 50 мг'],
        ]));
});

test('patient with all times chosen has nothing to set up', function () {
    $patient = User::factory()->patient()->create();
    timedPrescription($patient, catalogDrug('Гидроксизин')->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00']);

    $this->actingAs($patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('timeSetup', []));
});
