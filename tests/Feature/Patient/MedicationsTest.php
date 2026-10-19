<?php

use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionSource;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create();
});

test('patient sees current and upcoming prescriptions of the doctor', function () {
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
    $aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();

    $regular = regularPrescription($this->patient, $hydroxyzine, ['evening' => '1', 'morning' => '0.5'], [
        'prescriber_id' => $this->doctor->id,
        'instruction' => 'Может вызывать сонливость.',
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-11-30',
    ]);
    $asNeeded = Prescription::factory()->for($this->patient, 'patient')->for($hydroxyzine, 'strength')->asNeeded('1', 2)->create([
        'prescriber_id' => $this->doctor->id,
    ]);
    $upcoming = regularPrescription($this->patient, $aviandr, ['morning' => '1', 'evening' => '1'], ['starts_on' => '2026-10-25']);

    // Закрытые, завершённые, добавленные самим пациентом и чужие не показываются.
    regularPrescription($this->patient, $aviandr, attributes: ['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);
    regularPrescription($this->patient, $aviandr, attributes: ['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Changed]);
    regularPrescription($this->patient, $aviandr, attributes: ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-18']);
    regularPrescription($this->patient, $aviandr, attributes: ['source' => PrescriptionSource::Patient, 'starts_on' => '2026-12-01']);
    regularPrescription(User::factory()->patientOf($this->doctor)->create(), $hydroxyzine);

    $this->actingAs($this->patient)
        ->get(route('patient.medications'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Medications')
            ->has('prescriptions', 3)
            ->where('prescriptions.0.id', $upcoming->id)
            ->where('prescriptions.0.status', 'upcoming')
            ->where('prescriptions.1.id', $regular->id)
            ->where('prescriptions.1.drug.name', 'Гидроксизин')
            ->where('prescriptions.1.strength', '25 мг')
            ->where('prescriptions.1.intakes', [
                ['time_of_day' => 'Утром', 'dose' => '½ таблетки, 12,5 мг'],
                ['time_of_day' => 'Вечером', 'dose' => '1 таблетка, 25 мг'],
            ])
            ->where('prescriptions.1.instruction', 'Может вызывать сонливость.')
            ->where('prescriptions.1.starts_on', '10.10.2026')
            ->where('prescriptions.1.ends_on', '30.11.2026')
            ->where('prescriptions.1.prescriber', 'Смирнова Анна Сергеевна')
            ->where('prescriptions.2.id', $asNeeded->id)
            ->where('prescriptions.2.as_needed', ['dose' => '1 таблетка, 25 мг', 'limit' => 'Не больше 2 раз в сутки']),
        );
});

test('patient prescription ending today is still shown', function () {
    $strength = catalogDrug('Гидроксизин')->strengths()->sole();
    regularPrescription($this->patient, $strength, attributes: ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-19']);

    $this->actingAs($this->patient)
        ->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page->has('prescriptions', 1)->where('prescriptions.0.status', 'active'));
});

test('patient without prescriptions sees an empty list', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page->where('prescriptions', []));
});

test('doctor can not open the patient medications page', function () {
    $this->actingAs($this->doctor)
        ->get(route('patient.medications'))
        ->assertForbidden();
});
