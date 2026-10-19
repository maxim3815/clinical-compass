<?php

use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Enums\TimeOfDay;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->hydroxyzine = catalogDrug('Гидроксизин', [25 => true]);
    $this->strength = $this->hydroxyzine->strengths()->sole();
});

/**
 * @return list<array{TimeOfDay, string}>
 */
function intakesOf(Prescription $prescription): array
{
    return PrescriptionIntake::sortByTimeOfDay($prescription->intakes()->get())
        ->map(fn (PrescriptionIntake $intake) => [$intake->time_of_day, $intake->tablets])
        ->all();
}

test('edit form is filled with the prescription', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['evening' => '1', 'morning' => '0.5'], [
        'prescriber_id' => $this->doctor->id,
        'instruction' => 'После еды.',
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-11-30',
    ]);
    catalogDrug('Сертралин');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.edit', [$this->patient, $prescription]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/prescriptions/Form')
            // При изменении препарат не меняется.
            ->has('drugs', 1)
            ->where('drugs.0.id', $this->hydroxyzine->id)
            ->where('prescription', [
                'id' => $prescription->id,
                'drug_id' => $this->hydroxyzine->id,
                'drug_strength_id' => $this->strength->id,
                'kind' => 'regular',
                'intakes' => [
                    ['time_of_day' => 'morning', 'tablets' => 0.5],
                    ['time_of_day' => 'evening', 'tablets' => 1],
                ],
                'tablets' => null,
                'max_daily_doses' => null,
                'instruction' => 'После еды.',
                'starts_on' => '2026-10-10',
                'starts_on_label' => '10.10.2026',
                'ends_on' => '2026-11-30',
                'has_started' => true,
            ]),
        );
});

test('changing an active prescription closes it and creates a new version', function () {
    $old = regularPrescription($this->patient, $this->strength, ['morning' => '1'], [
        'prescriber_id' => $this->doctor->id,
        'instruction' => 'После еды.',
        'starts_on' => '2026-10-10',
    ]);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $old]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 2]],
            'instruction' => 'После еды.',
            // Дата начала у действующего назначения не меняется.
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-11-30',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Назначение изменено.']);

    $old->refresh();
    $new = Prescription::query()->where('previous_id', $old->id)->sole();

    expect($old->closed_at?->toDateTimeString())->toBe('2026-10-19 09:00:00')
        ->and($old->close_reason)->toBe(PrescriptionCloseReason::Changed)
        ->and($old->closed_by_id)->toBe($this->doctor->id)
        ->and($old->ends_on)->toBeNull()
        ->and(intakesOf($old))->toBe([[TimeOfDay::Morning, '1.0']])
        ->and($new->starts_on->toDateString())->toBe('2026-10-19')
        ->and($new->ends_on?->toDateString())->toBe('2026-11-30')
        ->and($new->drug_strength_id)->toBe($this->strength->id)
        ->and($new->prescriber_id)->toBe($this->doctor->id)
        ->and($new->instruction)->toBe('После еды.')
        ->and($new->closed_at)->toBeNull()
        ->and(intakesOf($new))->toBe([[TimeOfDay::Morning, '1.0'], [TimeOfDay::Evening, '2.0']]);

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)
            ->where('prescriptions.0.id', $new->id)
            ->where('prescriptions.0.starts_on', '19.10.2026')
            ->has('prescriptionHistory', 1)
            ->where('prescriptionHistory.0.id', $old->id)
            ->where('prescriptionHistory.0.status', 'changed')
            ->where('prescriptionHistory.0.status_label', 'Изменено')
            ->where('prescriptionHistory.0.starts_on', '10.10.2026')
            ->where('prescriptionHistory.0.closed_at', '19.10.2026 12:00')
            ->where('prescriptionHistory.0.intakes', [['time_of_day' => 'Утром', 'dose' => '1 таблетка, 25 мг', 'time' => null]])
            ->where('prescriptionHistory.0.can_change', false),
        );
});

test('changing a prescription that has not started edits it in place', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1', 'evening' => '1'], [
        'starts_on' => '2026-10-25',
    ]);
    $morning = $prescription->intakes()->where('time_of_day', TimeOfDay::Morning)->sole();

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [['time_of_day' => 'bedtime', 'tablets' => 1], ['time_of_day' => 'morning', 'tablets' => 0.5]],
            'starts_on' => '2026-10-22',
            'ends_on' => '2026-11-22',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Назначение изменено.']);

    $prescription->refresh();

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->closed_at)->toBeNull()
        ->and($prescription->starts_on->toDateString())->toBe('2026-10-22')
        ->and($prescription->ends_on?->toDateString())->toBe('2026-11-22')
        ->and($prescription->prescriber_id)->toBe($this->doctor->id)
        ->and(intakesOf($prescription))->toBe([[TimeOfDay::Morning, '0.5'], [TimeOfDay::Bedtime, '1.0']])
        // Приём с тем же временем суток остаётся той же записью.
        ->and($prescription->intakes()->where('time_of_day', TimeOfDay::Morning)->sole()->id)->toBe($morning->id);
});

test('saving without changes does nothing', function (array $attributes, array $intakes, array $payload) {
    $prescription = regularPrescription($this->patient, $this->strength, $intakes, [
        'prescriber_id' => $this->doctor->id,
        'instruction' => 'После еды.',
        ...$attributes,
    ]);
    $updatedAt = $prescription->updated_at;
    $this->travel(5)->minutes();

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'instruction' => 'После еды.',
            ...$payload,
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Изменений нет, назначение осталось прежним.']);

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->refresh()->closed_at)->toBeNull()
        ->and($prescription->updated_at?->eq($updatedAt))->toBeTrue();
})->with([
    'active regular' => [
        ['starts_on' => '2026-10-10', 'ends_on' => '2026-11-30'],
        ['morning' => '0.5', 'evening' => '1'],
        [
            'intakes' => [['time_of_day' => 'evening', 'tablets' => '1'], ['time_of_day' => 'morning', 'tablets' => 0.5]],
            'starts_on' => '2026-10-10',
            'ends_on' => '2026-11-30',
        ],
    ],
    'upcoming as needed' => [
        ['starts_on' => '2026-10-25', 'kind' => PrescriptionKind::AsNeeded, 'tablets' => '1', 'max_daily_doses' => 2],
        [],
        ['kind' => 'as_needed', 'intakes' => null, 'tablets' => 1, 'max_daily_doses' => 2, 'starts_on' => '2026-10-25'],
    ],
]);

test('drug and kind of a prescription do not change', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-10']);
    $sertraline = catalogDrug('Сертралин', [50 => true]);

    $this->actingAs($this->doctor);

    // Дозировка другого препарата не подходит.
    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($sertraline))
        ->assertSessionHasErrors(['drug_strength_id' => 'Выберите дозировку из справочника.']);

    // Препарат и вид из формы не учитываются.
    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
        'drug_id' => $sertraline->id,
        'kind' => 'as_needed',
        'tablets' => 1,
        'max_daily_doses' => 2,
        'intakes' => [['time_of_day' => 'morning', 'tablets' => 2]],
    ]))->assertSessionHasNoErrors();

    $new = Prescription::query()->where('previous_id', $prescription->id)->sole();

    expect($new->drug_id)->toBe($this->hydroxyzine->id)
        ->and($new->kind)->toBe(PrescriptionKind::Regular)
        ->and($new->tablets)->toBeNull()
        ->and(intakesOf($new))->toBe([[TimeOfDay::Morning, '2.0']]);
});

test('drug and strength removed from the catalog stay in a changed prescription', function () {
    $sertraline = catalogDrug('Сертралин', [50 => true]);
    $removed = DrugStrength::factory()->for($sertraline)->inactive()->create(['mg' => 25]);
    $otherRemoved = DrugStrength::factory()->for($sertraline)->inactive()->create(['mg' => 75]);
    $prescription = regularPrescription($this->patient, $removed, ['morning' => '1'], ['starts_on' => '2026-10-10']);
    $sertraline->update(['is_active' => false]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.edit', [$this->patient, $prescription]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('drugs', 1)
            ->where('drugs.0.id', $sertraline->id)
            ->has('drugs.0.strengths', 2)
            ->where('drugs.0.strengths.0.label', '25 мг')
            ->where('drugs.0.strengths.1.label', '50 мг'),
        );

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($sertraline, [
        'drug_strength_id' => $removed->id,
        'intakes' => [['time_of_day' => 'morning', 'tablets' => 2]],
    ]))->assertSessionHasNoErrors();

    $new = Prescription::query()->where('previous_id', $prescription->id)->sole();
    expect($new->drug_strength_id)->toBe($removed->id);

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $new]), prescriptionPayload($sertraline, [
        'drug_strength_id' => $otherRemoved->id,
    ]))->assertSessionHasErrors(['drug_strength_id' => 'Выберите дозировку из справочника.']);
});

test('active prescription can not end before today', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-10']);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'ends_on' => '2026-10-18',
        ]))
        ->assertSessionHasErrors(['ends_on' => 'Дата окончания не может быть раньше сегодняшнего дня.']);

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
        'ends_on' => '2026-10-19',
    ]))->assertSessionHasNoErrors();

    expect(Prescription::query()->where('previous_id', $prescription->id)->sole()->ends_on?->toDateString())->toBe('2026-10-19');
});

test('prescription that has not started can not be moved before today', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-25']);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'starts_on' => '2026-10-18',
        ]))
        ->assertSessionHasErrors(['starts_on' => 'Дата начала не может быть раньше сегодняшнего дня по времени пациента.']);
});

test('changed dates can not overlap another prescription of the same drug', function () {
    $current = regularPrescription($this->patient, $this->strength, ['morning' => '1'], [
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-10-31',
    ]);
    $next = regularPrescription($this->patient, $this->strength, ['morning' => '2'], ['starts_on' => '2026-11-01']);
    $message = 'У пациента уже есть регулярное назначение этого препарата на эти даты. Измените или отмените его.';

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $current]), prescriptionPayload($this->hydroxyzine, [
            'ends_on' => '2026-11-05',
        ]))
        ->assertSessionHasErrors(['ends_on' => $message]);

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $next]), prescriptionPayload($this->hydroxyzine, [
        'intakes' => [['time_of_day' => 'morning', 'tablets' => 2]],
        'starts_on' => '2026-10-30',
    ]))->assertSessionHasErrors(['starts_on' => $message]);

    expect(Prescription::query()->count())->toBe(2)
        ->and($current->refresh()->closed_at)->toBeNull();
});

test('changed, cancelled, completed or patient prescription can not be changed by the doctor', function (array $attributes) {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], [
        'starts_on' => '2026-10-01',
        ...$attributes,
    ]);
    $before = $prescription->fresh()?->toArray();
    $toast = ['type' => 'error', 'message' => 'Назначение уже изменено, отменено или завершено. Проверьте актуальные назначения.'];
    $card = route('doctor.patients.show', $this->patient);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.edit', [$this->patient, $prescription]))
        ->assertRedirect($card)
        ->assertInertiaFlash('toast', $toast);

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
        'intakes' => [['time_of_day' => 'evening', 'tablets' => 2]],
        'ends_on' => '2026-12-01',
    ]))
        ->assertRedirect($card)
        ->assertInertiaFlash('toast', $toast);

    $this->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]))
        ->assertRedirect($card)
        ->assertInertiaFlash('toast', $toast);

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->fresh()?->toArray())->toBe($before)
        ->and(intakesOf($prescription))->toBe([[TimeOfDay::Morning, '1.0']]);
})->with([
    'changed' => [['closed_at' => '2026-10-18 10:00:00', 'close_reason' => PrescriptionCloseReason::Changed]],
    'cancelled' => [['closed_at' => '2026-10-18 10:00:00', 'close_reason' => PrescriptionCloseReason::Cancelled]],
    'completed' => [['ends_on' => '2026-10-18']],
    'added by the patient' => [['source' => 'patient']],
]);
