<?php

use App\Actions\SyncDoseEvents;
use App\Actions\TakeDose;
use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\TimeOfDay;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->sertraline = catalogDrug('Сертралин', [50 => true]);
    $this->weekAgo = ['starts_on' => '2026-10-12', 'effective_at' => now()->subWeek()];
});

/**
 * Данные формы своего препарата: «другой» препарат, регулярно, утром в 8:00, с сегодняшнего дня.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownMedicationPayload(array $overrides = []): array
{
    return [
        'drug_id' => 'other',
        'drug_name' => 'Фенибут',
        'dose' => '1 таблетка 250 мг',
        'kind' => 'regular',
        'intakes' => [['time_of_day' => 'morning', 'time' => '08:00']],
        'max_daily_doses' => null,
        'starts_on' => '2026-10-19',
        'ends_on' => null,
        'prescribed_by' => null,
        ...$overrides,
    ];
}

/**
 * Приёмы назначения по порядку дня: время суток и время.
 *
 * @return list<array{TimeOfDay, string|null}>
 */
function ownIntakes(Prescription $prescription): array
{
    return PrescriptionIntake::sortByTimeOfDay($prescription->intakes()->get())
        ->map(fn (PrescriptionIntake $intake) => [$intake->time_of_day, $intake->time])
        ->all();
}

test('medication form offers active drugs of the catalog and of the list with names for search and suggested times', function () {
    catalogDrug('Агомелатин', attributes: ['is_active' => false]);
    $aviandr = catalogDrug('Авиандр', [20 => false]);
    $tramadol = Drug::factory()->known()->create(['name' => 'Трамадол', 'inn' => 'трамадол', 'trade_names' => ['Трамал', 'Залдиар']]);
    Drug::factory()->known()->inactive()->create(['name' => 'Пимозид']);

    $this->actingAs($this->patient)
        ->get(route('patient.own-medications.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/MedicationForm')
            ->where('drugs', [
                ['id' => $aviandr->id, 'name' => 'Авиандр', 'inn' => $aviandr->inn, 'trade_names' => []],
                ['id' => $this->sertraline->id, 'name' => 'Сертралин', 'inn' => $this->sertraline->inn, 'trade_names' => []],
                ['id' => $tramadol->id, 'name' => 'Трамадол', 'inn' => 'трамадол', 'trade_names' => ['Трамал', 'Залдиар']],
            ])
            ->where('timesOfDay.0', ['value' => 'morning', 'label' => 'Утром', 'time' => '08:00'])
            ->where('timesOfDay.3', ['value' => 'bedtime', 'label' => 'Перед сном', 'time' => '22:00'])
            ->where('today', '2026-10-19')
            ->where('timezone', 'Москва (UTC+3)')
            ->where('prescription', null),
        );
});

test('patient adds a catalog drug taken regularly and gets dose events at once', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload([
            'drug_id' => $this->sertraline->id,
            'drug_name' => 'не сохраняется',
            'dose' => '1 таблетка 50 мг',
            'intakes' => [
                ['time_of_day' => 'evening', 'time' => '20:00'],
                ['time_of_day' => 'morning', 'time' => '08:00'],
            ],
            'ends_on' => '2026-11-30',
            'prescribed_by' => 'терапевт Петрова И. А.',
        ]))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Препарат добавлен.']);

    $prescription = Prescription::query()->sole();

    expect($prescription->patient_id)->toBe($this->patient->id)
        ->and($prescription->source)->toBe(PrescriptionSource::Patient)
        ->and($prescription->prescriber_id)->toBeNull()
        ->and($prescription->drug_id)->toBe($this->sertraline->id)
        ->and($prescription->drug_name)->toBeNull()
        ->and($prescription->drug_strength_id)->toBeNull()
        ->and($prescription->dose)->toBe('1 таблетка 50 мг')
        ->and($prescription->kind)->toBe(PrescriptionKind::Regular)
        ->and($prescription->instruction)->toBeNull()
        ->and($prescription->starts_on->toDateString())->toBe('2026-10-19')
        ->and($prescription->ends_on?->toDateString())->toBe('2026-11-30')
        ->and($prescription->prescribed_by)->toBe('терапевт Петрова И. А.')
        ->and(ownIntakes($prescription))->toBe([[TimeOfDay::Morning, '08:00'], [TimeOfDay::Evening, '20:00']])
        ->and($prescription->intakes->every(fn (PrescriptionIntake $intake) => $intake->tablets === null && $intake->time_set_at?->eq(now())))->toBeTrue()
        // Сегодняшние 8:00 уже прошли к моменту добавления.
        ->and(doseSchedule($this->patient))->toBe([
            '2026-10-19 evening 2026-10-19 17:00',
            '2026-10-20 morning 2026-10-20 05:00',
            '2026-10-20 evening 2026-10-20 17:00',
        ])
        ->and($this->patient->doseEvents()->whereNotNull('tablets')->exists())->toBeFalse();

    $this->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions', [])
            ->has('ownMedications', 1)
            ->where('ownMedications.0.id', $prescription->id)
            ->where('ownMedications.0.source', 'patient')
            ->where('ownMedications.0.drug', ['code' => $this->sertraline->code, 'name' => 'Сертралин', 'kind' => 'catalog'])
            ->where('ownMedications.0.strength', null)
            ->where('ownMedications.0.dose', '1 таблетка 50 мг')
            ->where('ownMedications.0.intakes', [
                ['time_of_day' => 'Утром', 'dose' => null, 'time' => '8:00'],
                ['time_of_day' => 'Вечером', 'dose' => null, 'time' => '20:00'],
            ])
            ->where('ownMedications.0.starts_on', '19.10.2026')
            ->where('ownMedications.0.ends_on', '30.11.2026')
            ->where('ownMedications.0.prescriber', null)
            ->where('ownMedications.0.prescribed_by', 'терапевт Петрова И. А.')
            ->where('ownMedications.0.can_change', false),
        );
});

test('patient adds a drug from the list of drugs from other doctors', function () {
    $tramadol = Drug::factory()->known(['serotonergic'])->create(['name' => 'Трамадол', 'trade_names' => ['Трамал']]);

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload([
            'drug_id' => $tramadol->id,
            'dose' => '1 капсула 50 мг',
            'prescribed_by' => 'невролог',
        ]))
        ->assertRedirect(route('patient.medications'));

    $prescription = Prescription::query()->sole();

    expect($prescription->source)->toBe(PrescriptionSource::Patient)
        ->and($prescription->drug_id)->toBe($tramadol->id)
        ->and($prescription->drug_name)->toBeNull()
        ->and($prescription->trade_name)->toBeNull()
        ->and($prescription->drugName())->toBe('Трамадол')
        ->and($prescription->doctorDrugName())->toBe('Трамадол');
});

test('patient sees the trade name the drug was found by, the doctor sees the drug and the trade name', function () {
    $tramadol = Drug::factory()->known(['serotonergic'])->create(['name' => 'Трамадол', 'trade_names' => ['Трамал', 'Залдиар']]);

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload([
            'drug_id' => $tramadol->id,
            'trade_name' => 'Трамал',
            'dose' => '1 капсула 50 мг',
            'intakes' => [['time_of_day' => 'evening', 'time' => '20:00']],
        ]))
        ->assertRedirect(route('patient.medications'));

    $prescription = Prescription::query()->sole();

    expect($prescription->trade_name)->toBe('Трамал')
        ->and($prescription->auditSnapshot()['trade_name'])->toBe('Трамал');

    $this->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownMedications.0.drug', ['code' => $tramadol->code, 'name' => 'Трамал', 'kind' => 'known']));

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('today.groups.0.doses.0.drug', 'Трамал'));

    $this->get(route('patient.own-medications.edit', $prescription))
        ->assertInertia(fn (Assert $page) => $page->where('prescription.drug_name', 'Трамал'));

    // Изменение дозы название не теряет.
    $this->put(route('patient.own-medications.update', $prescription), ownMedicationPayload([
        'dose' => '2 капсулы 50 мг',
        'intakes' => [['time_of_day' => 'evening', 'time' => '20:00']],
    ]))->assertRedirect(route('patient.medications'));

    expect(Prescription::query()->open()->sole()->trade_name)->toBe('Трамал');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownMedications.0.drug', ['code' => $tramadol->code, 'name' => 'Трамадол (Трамал)', 'kind' => 'known', 'reason' => $tramadol->reason]));
});

test('trade name must be one of the names of the chosen drug', function (Closure $payload) {
    $tramadol = Drug::factory()->known()->create(['trade_names' => ['Трамал']]);
    Drug::factory()->known()->create(['trade_names' => ['Кордарон']]);

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload($payload($tramadol)))
        ->assertSessionHasErrors(['trade_name' => 'Выберите препарат из вариантов под полем.']);

    expect(Prescription::query()->exists())->toBeFalse();
})->with([
    'name of another drug' => [fn (Drug $drug): array => ['drug_id' => $drug->id, 'trade_name' => 'Кордарон']],
    'case differs' => [fn (Drug $drug): array => ['drug_id' => $drug->id, 'trade_name' => 'трамал']],
]);

test('trade name is not saved for another drug', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload(['trade_name' => 'Трамал']))
        ->assertRedirect(route('patient.medications'));

    expect(Prescription::query()->sole()->trade_name)->toBeNull();
});

test('inactive drug from the list can not be added', function () {
    $tramadol = Drug::factory()->known()->inactive()->create();

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_id' => $tramadol->id]))
        ->assertSessionHasErrors(['drug_id' => 'Выберите препарат из вариантов под полем.']);

    expect(Prescription::query()->exists())->toBeFalse();
});

test('patient adds another drug as needed with or without a daily limit', function (?int $limit) {
    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload([
            'dose' => '½ таблетки',
            'kind' => 'as_needed',
            'max_daily_doses' => $limit,
        ]))
        ->assertRedirect(route('patient.medications'));

    $prescription = Prescription::query()->sole();

    expect($prescription->drug_id)->toBeNull()
        ->and($prescription->drug_name)->toBe('Фенибут')
        ->and($prescription->kind)->toBe(PrescriptionKind::AsNeeded)
        ->and($prescription->tablets)->toBeNull()
        ->and($prescription->max_daily_doses)->toBe($limit)
        // Приёмы «по необходимости» не сохраняются, даже если пришли.
        ->and($prescription->intakes()->count())->toBe(0)
        ->and($this->patient->doseEvents()->count())->toBe(0);

    $this->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownMedications.0.drug', ['code' => null, 'name' => 'Фенибут', 'kind' => null])
            ->where('ownMedications.0.as_needed', [
                'dose' => '½ таблетки',
                'limit' => $limit === null ? null : 'Не больше 3 раз в сутки',
            ]),
        );
})->with([
    'without a limit' => [null],
    'with a limit' => [3],
]);

test('medication form explains what is wrong', function (array $overrides, array $errors) {
    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload($overrides))
        ->assertSessionHasErrors($errors);

    expect(Prescription::query()->count())->toBe(0);
})->with([
    'no drug' => [['drug_id' => null], ['drug_id' => 'Напишите название и выберите препарат из вариантов под полем.']],
    'inactive drug' => [fn () => ['drug_id' => catalogDrug('Агомелатин', attributes: ['is_active' => false])->id], ['drug_id' => 'Выберите препарат из вариантов под полем.']],
    'unknown drug' => [['drug_id' => 'something'], ['drug_id' => 'Выберите препарат из вариантов под полем.']],
    'another drug without a name' => [['drug_name' => ' '], ['drug_name' => 'Напишите название препарата.']],
    'too long name' => [['drug_name' => str_repeat('а', 201)], ['drug_name' => 'Название длиннее 200 символов. Сократите его.']],
    'no dose' => [['dose' => ''], ['dose' => 'Напишите дозу, например «1 таблетка 50 мг».']],
    'too long dose' => [['dose' => str_repeat('а', 101)], ['dose' => 'Доза длиннее 100 символов. Сократите её.']],
    'no kind' => [['kind' => 'sometimes'], ['kind' => 'Выберите, как принимать препарат.']],
    'no intakes' => [['intakes' => []], ['intakes' => 'Добавьте хотя бы один приём.']],
    'same time of day twice' => [
        ['intakes' => [['time_of_day' => 'morning', 'time' => '08:00'], ['time_of_day' => 'morning', 'time' => '09:00']]],
        ['intakes.1.time_of_day' => 'Для каждого приёма выберите разное время суток.'],
    ],
    'same time twice' => [
        ['intakes' => [['time_of_day' => 'morning', 'time' => '08:00'], ['time_of_day' => 'evening', 'time' => '08:00']]],
        ['intakes.1.time' => 'Время совпадает с другим приёмом.'],
    ],
    'no time' => [['intakes' => [['time_of_day' => 'morning', 'time' => '']]], ['intakes.0.time' => 'Укажите время приёма.']],
    'wrong time' => [['intakes' => [['time_of_day' => 'morning', 'time' => '25:00']]], ['intakes.0.time' => 'Укажите время в формате ЧЧ:ММ.']],
    'too many doses a day' => [['kind' => 'as_needed', 'max_daily_doses' => 11], ['max_daily_doses' => 'Укажите от 1 до 10 раз в сутки.']],
    'start in the past' => [['starts_on' => '2026-10-18'], ['starts_on' => 'Дата начала не может быть раньше сегодняшнего дня.']],
    'end before start' => [['starts_on' => '2026-10-25', 'ends_on' => '2026-10-24'], ['ends_on' => 'Дата окончания не может быть раньше даты начала.']],
    'too long prescriber' => [['prescribed_by' => str_repeat('а', 201)], ['prescribed_by' => 'Здесь больше 200 символов. Сократите запись.']],
]);

test('a catalog drug prescribed by the doctor with the same kind can not be added', function () {
    regularPrescription($this->patient, $this->sertraline->strengths()->sole(), attributes: [
        'prescriber_id' => $this->doctor->id,
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-10-31',
    ]);
    $this->actingAs($this->patient);

    $this->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_id' => $this->sertraline->id]))
        ->assertSessionHasErrors(['drug_id' => 'Врач уже назначил вам этот препарат на эти даты. Отмечайте приём в разделе «Назначил врач».']);

    expect(Prescription::query()->where('source', PrescriptionSource::Patient)->count())->toBe(0);

    // Другой вид приёма, «другой» препарат с тем же названием и даты после назначения врача не мешают.
    $this->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_id' => $this->sertraline->id, 'kind' => 'as_needed']))->assertSessionHasNoErrors();
    $this->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_name' => 'Сертралин']))->assertSessionHasNoErrors();
    $this->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_id' => $this->sertraline->id, 'starts_on' => '2026-11-01']))->assertSessionHasNoErrors();

    expect(Prescription::query()->where('source', PrescriptionSource::Patient)->count())->toBe(3);
});

test('a catalog drug prescribed by the doctor as needed can not be added as needed', function () {
    Prescription::factory()->for($this->patient, 'patient')->for($this->sertraline->strengths()->sole(), 'strength')->asNeeded()->create();

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), ownMedicationPayload(['drug_id' => $this->sertraline->id, 'kind' => 'as_needed']))
        ->assertSessionHasErrors(['drug_id' => 'Врач уже назначил вам этот препарат «по необходимости» на эти даты. Отмечайте приёмы по его назначению на экране «Сегодня».']);
});

test('patient changes a started medication and gets a new version', function () {
    $prescription = ownPrescription($this->patient, $this->sertraline, ['morning' => '08:00'], [...$this->weekAgo, 'dose' => '1 таблетка 50 мг']);
    app(SyncDoseEvents::class)->handle($this->patient);
    $morningSetAt = $prescription->intakes()->sole()->time_set_at;
    $this->actingAs($this->patient);

    $this->get(route('patient.own-medications.edit', $prescription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/MedicationForm')
            ->where('prescription', [
                'id' => $prescription->id,
                'drug_name' => 'Сертралин',
                'kind' => 'regular',
                'dose' => '1 таблетка 50 мг',
                'intakes' => [['time_of_day' => 'morning', 'time' => '08:00']],
                'max_daily_doses' => null,
                'starts_on' => '2026-10-12',
                'starts_on_label' => '12.10.2026',
                'ends_on' => null,
                'has_started' => true,
                'prescribed_by' => null,
            ]),
        );

    $this->put(route('patient.own-medications.update', $prescription), ownMedicationPayload([
        // Препарат, вид и дата начала начатого препарата не меняются, даже если пришли.
        'drug_id' => 'other',
        'kind' => 'as_needed',
        'starts_on' => '2026-10-25',
        'dose' => '2 таблетки по 50 мг',
        'intakes' => [['time_of_day' => 'morning', 'time' => '08:00'], ['time_of_day' => 'evening', 'time' => '21:00']],
    ]))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Изменения сохранены.']);

    $previous = $prescription->fresh();
    $next = Prescription::query()->where('previous_id', $prescription->id)->sole();

    expect($previous?->close_reason)->toBe(PrescriptionCloseReason::Changed)
        ->and($previous?->closed_by_id)->toBe($this->patient->id)
        ->and($previous?->dose)->toBe('1 таблетка 50 мг')
        ->and($next->source)->toBe(PrescriptionSource::Patient)
        ->and($next->drug_id)->toBe($this->sertraline->id)
        ->and($next->kind)->toBe(PrescriptionKind::Regular)
        ->and($next->dose)->toBe('2 таблетки по 50 мг')
        ->and($next->starts_on->toDateString())->toBe('2026-10-19')
        ->and(ownIntakes($next))->toBe([[TimeOfDay::Morning, '08:00'], [TimeOfDay::Evening, '21:00']])
        // Время утреннего приёма не менялось: момент его выбора переходит в новую версию.
        ->and($next->intakes->firstWhere('time_of_day', TimeOfDay::Morning)?->time_set_at?->eq($morningSetAt))->toBeTrue()
        // Сегодняшний утренний приём остался у прежней версии, будущие перешли к новой.
        ->and(doseSchedule($this->patient))->toBe([
            '2026-10-19 morning 2026-10-19 05:00',
            '2026-10-19 evening 2026-10-19 18:00',
            '2026-10-20 morning 2026-10-20 05:00',
            '2026-10-20 evening 2026-10-20 18:00',
        ])
        ->and(doseOn($this->patient, '2026-10-19', 'morning')->prescription_id)->toBe($prescription->id)
        ->and(doseOn($this->patient, '2026-10-20', 'morning')->prescription_id)->toBe($next->id);
});

test('a dose marked in advance is not planned again after the patient changes the medication', function () {
    $prescription = ownPrescription($this->patient, times: ['afternoon' => '12:30'], attributes: $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);
    app(TakeDose::class)->handle($this->patient, doseOn($this->patient, '2026-10-19', 'afternoon'), null);

    $this->actingAs($this->patient)
        ->put(route('patient.own-medications.update', $prescription), ownMedicationPayload([
            'dose' => '2 таблетки',
            'intakes' => [['time_of_day' => 'afternoon', 'time' => '12:30']],
        ]))
        ->assertSessionHasNoErrors();

    $today = $this->patient->doseEvents()->where('local_date', '2026-10-19')->sole();

    expect($today->prescription_id)->toBe($prescription->id)
        ->and($today->status)->toBe(DoseEventStatus::Taken)
        ->and(doseOn($this->patient, '2026-10-20', 'afternoon')->prescription_id)->not->toBe($prescription->id);
});

test('patient edits an upcoming medication in place', function () {
    $prescription = ownPrescription($this->patient, times: ['morning' => '08:00', 'evening' => '20:00'], attributes: ['starts_on' => '2026-10-25']);
    $morning = $prescription->intakes()->where('time_of_day', 'morning')->sole();

    $this->actingAs($this->patient)
        ->put(route('patient.own-medications.update', $prescription), ownMedicationPayload([
            'dose' => '2 таблетки',
            'intakes' => [['time_of_day' => 'morning', 'time' => '07:30'], ['time_of_day' => 'bedtime', 'time' => '23:00']],
            'starts_on' => '2026-10-20',
            'prescribed_by' => 'невролог',
        ]))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Изменения сохранены.']);

    $edited = $prescription->fresh();

    expect(Prescription::query()->count())->toBe(1)
        ->and($edited?->closed_at)->toBeNull()
        ->and($edited?->dose)->toBe('2 таблетки')
        ->and($edited?->starts_on->toDateString())->toBe('2026-10-20')
        ->and($edited?->prescribed_by)->toBe('невролог')
        ->and(ownIntakes($prescription))->toBe([[TimeOfDay::Morning, '07:30'], [TimeOfDay::Bedtime, '23:00']])
        ->and($prescription->intakes()->where('time_of_day', 'morning')->sole()->id)->toBe($morning->id)
        ->and(doseSchedule($this->patient))->toBe([
            '2026-10-20 morning 2026-10-20 04:30',
            '2026-10-20 bedtime 2026-10-20 20:00',
        ]);
});

test('saving the medication without changes keeps it as it is', function () {
    $prescription = ownPrescription($this->patient, attributes: [...$this->weekAgo, 'dose' => '1 таблетка 250 мг']);
    $before = $prescription->fresh()?->toArray();

    $this->actingAs($this->patient)
        ->put(route('patient.own-medications.update', $prescription), ownMedicationPayload())
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Изменений нет, препарат остался прежним.']);

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->fresh()?->toArray())->toBe($before);
});

test('patient stops a medication and its future doses disappear', function () {
    $prescription = ownPrescription($this->patient, times: ['morning' => '08:00', 'evening' => '20:00'], attributes: $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.stop', $prescription))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Препарат убран из списка.']);

    $stopped = $prescription->fresh();

    expect($stopped?->close_reason)->toBe(PrescriptionCloseReason::Cancelled)
        ->and($stopped?->closed_by_id)->toBe($this->patient->id)
        ->and($stopped?->cancel_reason)->toBeNull()
        // Прошедший утренний приём ещё можно отметить, будущие удалены.
        ->and(doseSchedule($this->patient))->toBe(['2026-10-19 morning 2026-10-19 05:00']);

    $this->get(route('patient.medications'))->assertInertia(fn (Assert $page) => $page->where('ownMedications', []));
});

test('changed, stopped or completed medication can not be changed', function (array $attributes) {
    $prescription = ownPrescription($this->patient, attributes: [...$this->weekAgo, ...$attributes]);
    $before = $prescription->fresh()?->toArray();
    $toast = ['type' => 'error', 'message' => 'Этот препарат уже изменили или убрали, либо его приём закончился. Список обновлён.'];
    $this->actingAs($this->patient);

    $this->get(route('patient.own-medications.edit', $prescription))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', $toast);

    $this->put(route('patient.own-medications.update', $prescription), ownMedicationPayload(['dose' => '2 таблетки']))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', $toast);

    $this->post(route('patient.own-medications.stop', $prescription))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', $toast);

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->fresh()?->toArray())->toBe($before);
})->with([
    'changed' => [['closed_at' => '2026-10-18 10:00:00', 'close_reason' => PrescriptionCloseReason::Changed]],
    'stopped' => [['closed_at' => '2026-10-18 10:00:00', 'close_reason' => PrescriptionCloseReason::Cancelled]],
    'completed' => [['ends_on' => '2026-10-18']],
]);

test('patient can not change prescriptions of the doctor and medications of other patients', function (Closure $prescription) {
    $prescription = $prescription($this->patient, $this->sertraline);
    $before = $prescription->fresh()?->toArray();
    $this->actingAs($this->patient);

    $this->get(route('patient.own-medications.edit', $prescription))->assertNotFound();
    $this->put(route('patient.own-medications.update', $prescription), ownMedicationPayload(['dose' => '2 таблетки']))->assertNotFound();
    $this->post(route('patient.own-medications.stop', $prescription))->assertNotFound();

    expect(Prescription::query()->count())->toBe(1)
        ->and($prescription->fresh()?->toArray())->toBe($before);
})->with([
    'of the doctor' => fn (User $patient, Drug $drug) => timedPrescription($patient, $drug->strengths()->sole()),
    'of another patient' => fn (User $patient, Drug $drug) => ownPrescription(User::factory()->patient()->create(), $drug),
]);

test('doctors can not open the patient medication form', function () {
    $prescription = ownPrescription($this->patient);
    $this->actingAs($this->doctor);

    $this->get(route('patient.own-medications.create'))->assertForbidden();
    $this->post(route('patient.own-medications.store'), ownMedicationPayload())->assertForbidden();
    $this->get(route('patient.own-medications.edit', $prescription))->assertForbidden();
    $this->post(route('patient.own-medications.stop', $prescription))->assertForbidden();
});

test('non numeric medication id is not found', function () {
    $this->actingAs($this->patient)
        ->get('/patient/medications/abc/edit')
        ->assertNotFound();
});
