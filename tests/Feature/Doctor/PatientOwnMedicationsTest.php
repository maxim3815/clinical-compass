<?php

use App\Enums\PrescriptionCloseReason;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->sertraline = catalogDrug('Сертралин', [50 => true]);
});

test('doctor sees current medications added by the patient apart from prescriptions', function () {
    $regular = ownPrescription($this->patient, $this->sertraline, ['evening' => '21:00', 'morning' => '08:00'], [
        'dose' => '1 таблетка 50 мг',
        'starts_on' => '2026-10-10',
        'prescribed_by' => 'терапевт Петрова И. А.',
    ]);
    $asNeeded = ownPrescription($this->patient, times: [], attributes: [
        'kind' => 'as_needed',
        'dose' => '½ таблетки',
        'max_daily_doses' => 2,
        'starts_on' => '2026-10-25',
    ]);
    // Изменённые, прекращённые и завершённые не показываются, в историю назначений не попадают.
    ownPrescription($this->patient, attributes: ['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Changed]);
    ownPrescription($this->patient, attributes: ['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);
    ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-18']);
    $prescription = regularPrescription($this->patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), attributes: ['prescriber_id' => $this->doctor->id]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)
            ->where('prescriptions.0.id', $prescription->id)
            ->where('prescriptionHistory', [])
            ->has('ownMedications', 2)
            ->where('ownMedications.1.id', $asNeeded->id)
            ->where('ownMedications.1.drug.name', 'Фенибут')
            ->where('ownMedications.1.kind_label', 'По необходимости')
            ->where('ownMedications.1.as_needed', ['dose' => '½ таблетки', 'limit' => 'Не больше 2 раз в сутки'])
            ->where('ownMedications.1.status', 'upcoming')
            ->where('ownMedications.0.id', $regular->id)
            ->where('ownMedications.0.source', 'patient')
            ->where('ownMedications.0.drug.name', 'Сертралин')
            ->where('ownMedications.0.strength', null)
            ->where('ownMedications.0.dose', '1 таблетка 50 мг')
            ->where('ownMedications.0.intakes', [
                ['time_of_day' => 'Утром', 'dose' => null, 'time' => '8:00'],
                ['time_of_day' => 'Вечером', 'dose' => null, 'time' => '21:00'],
            ])
            ->where('ownMedications.0.starts_on', '10.10.2026')
            ->where('ownMedications.0.ends_on', null)
            ->where('ownMedications.0.prescriber', null)
            ->where('ownMedications.0.prescribed_by', 'терапевт Петрова И. А.')
            ->where('ownMedications.0.can_change', false),
        );
});

test('doctor can not change or cancel a medication added by the patient', function () {
    $prescription = ownPrescription($this->patient, $this->sertraline, attributes: ['starts_on' => '2026-10-10']);
    $before = $prescription->fresh()?->toArray();
    $this->actingAs($this->doctor);

    $this->get(route('doctor.patients.prescriptions.edit', [$this->patient, $prescription]))
        ->assertForbidden();

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->sertraline, [
        'intakes' => [['time_of_day' => 'evening', 'tablets' => 2]],
    ]))
        ->assertForbidden();

    $this->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]))
        ->assertForbidden();

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->fresh()?->toArray())->toBe($before);
});

test('medications added by a foreign patient are not found for the doctor', function () {
    $foreign = User::factory()->patient()->create();
    $prescription = ownPrescription($foreign, $this->sertraline);
    $this->actingAs($this->doctor);

    $this->get(route('doctor.patients.prescriptions.edit', [$foreign, $prescription]))->assertNotFound();
    $this->post(route('doctor.patients.prescriptions.cancel', [$foreign, $prescription]))->assertNotFound();
});
