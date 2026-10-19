<?php

use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\RussianTimezone;
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
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
});

test('prescription form offers active drugs with active strengths', function () {
    $sertraline = catalogDrug('Сертралин', [100 => false, 50 => true]);
    DrugStrength::factory()->for($sertraline)->inactive()->create(['mg' => 25]);
    $hydroxyzine = catalogDrug('Гидроксизин');
    catalogDrug('Агомелатин', attributes: ['is_active' => false]);
    $withoutStrengths = catalogDrug('Пароксетин', []);
    DrugStrength::factory()->for($withoutStrengths)->inactive()->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.create', $this->patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/prescriptions/Form')
            ->where('patient', ['id' => $this->patient->id, 'name' => 'Иванов Пётр Алексеевич'])
            ->has('drugs', 2)
            ->where('drugs.0.id', $hydroxyzine->id)
            ->where('drugs.0.patient_instruction', $hydroxyzine->patient_instruction)
            ->where('drugs.0.regimen', $hydroxyzine->regimen)
            ->where('drugs.1.name', 'Сертралин')
            ->where('drugs.1.strengths.0.label', '50 мг')
            ->where('drugs.1.strengths.0.divisible', true)
            ->where('drugs.1.strengths.1.label', '100 мг')
            ->where('drugs.1.strengths.1.divisible', false)
            ->has('drugs.1.strengths', 2)
            ->has('timesOfDay', 4)
            ->where('timesOfDay.0', ['value' => 'morning', 'label' => 'Утром'])
            ->where('today', '2026-10-19')
            ->where('prescription', null),
        );
});

test('doctor prescribes a regular intake with a different dose for each intake', function () {
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, [
            'intakes' => [
                ['time_of_day' => 'evening', 'tablets' => 1],
                ['time_of_day' => 'morning', 'tablets' => 0.5],
            ],
            'instruction' => 'Может вызывать сонливость.',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Препарат назначен.']);

    $prescription = Prescription::query()->sole();

    expect($prescription->patient_id)->toBe($this->patient->id)
        ->and($prescription->source)->toBe(PrescriptionSource::Doctor)
        ->and($prescription->prescriber_id)->toBe($this->doctor->id)
        ->and($prescription->drug_id)->toBe($hydroxyzine->id)
        ->and($prescription->kind)->toBe(PrescriptionKind::Regular)
        ->and($prescription->tablets)->toBeNull()
        ->and($prescription->max_daily_doses)->toBeNull()
        ->and($prescription->starts_on->toDateString())->toBe('2026-10-19')
        ->and($prescription->ends_on)->toBeNull()
        ->and($prescription->closed_at)->toBeNull()
        ->and(PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
            ->map(fn (PrescriptionIntake $intake) => [$intake->time_of_day, $intake->tablets])
            ->all())->toBe([[TimeOfDay::Morning, '0.5'], [TimeOfDay::Evening, '1.0']]);

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)
            ->where('prescriptions.0.id', $prescription->id)
            ->where('prescriptions.0.drug.name', 'Гидроксизин')
            ->where('prescriptions.0.strength', '25 мг')
            ->where('prescriptions.0.kind', 'regular')
            ->where('prescriptions.0.kind_label', 'Регулярно')
            ->where('prescriptions.0.intakes', [
                ['time_of_day' => 'Утром', 'dose' => '½ таблетки, 12,5 мг', 'time' => null],
                ['time_of_day' => 'Вечером', 'dose' => '1 таблетка, 25 мг', 'time' => null],
            ])
            ->where('prescriptions.0.as_needed', null)
            ->where('prescriptions.0.instruction', 'Может вызывать сонливость.')
            ->where('prescriptions.0.starts_on', '19.10.2026')
            ->where('prescriptions.0.ends_on', null)
            ->where('prescriptions.0.status', 'active')
            ->where('prescriptions.0.prescriber', 'Смирнова Анна Сергеевна')
            ->where('prescriptions.0.can_change', true)
            ->has('prescriptionHistory', 0),
        );
});

test('doctor prescribes an intake as needed', function () {
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, [
            'kind' => 'as_needed',
            // Приёмы у вида «по необходимости» не сохраняются, даже если пришли.
            'intakes' => [['time_of_day' => 'morning', 'tablets' => 5]],
            'tablets' => 1.5,
            'max_daily_doses' => 3,
            'starts_on' => '2026-10-20',
            'ends_on' => '2026-11-01',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    $prescription = Prescription::query()->sole();

    expect($prescription->kind)->toBe(PrescriptionKind::AsNeeded)
        ->and($prescription->tablets)->toBe('1.5')
        ->and($prescription->max_daily_doses)->toBe(3)
        ->and($prescription->intakes)->toBeEmpty()
        ->and($prescription->starts_on->toDateString())->toBe('2026-10-20')
        ->and($prescription->ends_on?->toDateString())->toBe('2026-11-01');

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.0.kind_label', 'По необходимости')
            ->where('prescriptions.0.intakes', [])
            ->where('prescriptions.0.as_needed', [
                'dose' => '1½ таблетки, 37,5 мг',
                'limit' => 'Не больше 3 раз в сутки',
            ])
            ->where('prescriptions.0.status', 'upcoming')
            ->where('prescriptions.0.status_label', 'Ещё не началось')
            ->where('prescriptions.0.starts_on', '20.10.2026')
            ->where('prescriptions.0.ends_on', '01.11.2026'),
        );
});

test('empty instruction is saved as no instruction', function () {
    $hydroxyzine = catalogDrug('Гидроксизин');

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['instruction' => '   ']))
        ->assertSessionHasNoErrors();

    expect(Prescription::query()->sole()->instruction)->toBeNull();
});

test('half a tablet is rejected for a strength that can not be divided', function (array $overrides, string $field) {
    $aviandr = catalogDrug('Авиандр', [20 => false]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($aviandr, $overrides))
        ->assertSessionHasErrors([$field => 'Таблетку этой дозировки делить нельзя.']);

    expect(Prescription::query()->count())->toBe(0);
})->with([
    'half' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => 0.5]]], 'intakes.0.tablets'],
    'one and a half in the second intake' => [
        ['intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 1.5]]],
        'intakes.1.tablets',
    ],
    'as needed' => [['kind' => 'as_needed', 'tablets' => 0.5, 'max_daily_doses' => 2], 'tablets'],
]);

test('whole tablets of a strength that can not be divided are accepted', function () {
    $aviandr = catalogDrug('Авиандр', [20 => false]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($aviandr, [
            'intakes' => [['time_of_day' => 'morning', 'tablets' => '1'], ['time_of_day' => 'evening', 'tablets' => 2]],
        ]))
        ->assertSessionHasNoErrors();

    expect(Prescription::query()->sole()->intakes)->toHaveCount(2);
});

test('invalid prescription is rejected', function (array $overrides, string $field, string $message) {
    $hydroxyzine = catalogDrug('Гидроксизин');

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, $overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect(Prescription::query()->count())->toBe(0);
})->with([
    'no drug' => [['drug_id' => null], 'drug_id', 'Выберите препарат.'],
    'unknown drug' => [['drug_id' => 999999], 'drug_id', 'Выберите препарат из справочника.'],
    'drug is not a number' => [['drug_id' => 'abc'], 'drug_id', 'Выберите препарат из справочника.'],
    'no strength' => [['drug_strength_id' => null], 'drug_strength_id', 'Выберите дозировку.'],
    'strength is not a number' => [['drug_strength_id' => 'abc'], 'drug_strength_id', 'Выберите дозировку из справочника.'],
    'unknown kind' => [['kind' => 'sometimes'], 'kind', 'Выберите, как принимать препарат.'],
    'no intakes' => [['intakes' => []], 'intakes', 'Добавьте хотя бы один приём.'],
    'five intakes' => [
        ['intakes' => array_fill(0, 5, ['time_of_day' => 'morning', 'tablets' => 1])],
        'intakes',
        'В сутки можно назначить не больше 4 приёмов.',
    ],
    'same time of day twice' => [
        ['intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'morning', 'tablets' => 1]]],
        'intakes.1.time_of_day',
        'Время суток у приёмов не должно повторяться.',
    ],
    'unknown time of day' => [['intakes' => [['time_of_day' => 'night', 'tablets' => 1]]], 'intakes.0.time_of_day', 'Выберите время суток.'],
    'extra intake field' => [
        ['intakes' => [['time_of_day' => 'morning', 'tablets' => 1, 'time' => '08:00']]],
        'intakes.0',
        'Приём описан неверно.',
    ],
    'no tablets' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => null]]], 'intakes.0.tablets', 'Укажите от ½ до 10 таблеток.'],
    'zero tablets' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => 0]]], 'intakes.0.tablets', 'Укажите от ½ до 10 таблеток.'],
    'too many tablets' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => 10.5]]], 'intakes.0.tablets', 'Укажите от ½ до 10 таблеток.'],
    'tablets as text' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => 'много']]], 'intakes.0.tablets', 'Укажите от ½ до 10 таблеток.'],
    'quarter of a tablet' => [['intakes' => [['time_of_day' => 'morning', 'tablets' => 1.25]]], 'intakes.0.tablets', 'Таблетку можно делить только пополам.'],
    'as needed without tablets' => [['kind' => 'as_needed', 'max_daily_doses' => 3], 'tablets', 'Укажите от ½ до 10 таблеток.'],
    'as needed without daily limit' => [['kind' => 'as_needed', 'tablets' => 1], 'max_daily_doses', 'Укажите от 1 до 10 раз в сутки.'],
    'as needed zero times a day' => [['kind' => 'as_needed', 'tablets' => 1, 'max_daily_doses' => 0], 'max_daily_doses', 'Укажите от 1 до 10 раз в сутки.'],
    'as needed too often' => [['kind' => 'as_needed', 'tablets' => 1, 'max_daily_doses' => 11], 'max_daily_doses', 'Укажите от 1 до 10 раз в сутки.'],
    'as needed with a quarter of a tablet' => [['kind' => 'as_needed', 'tablets' => 0.75, 'max_daily_doses' => 2], 'tablets', 'Таблетку можно делить только пополам.'],
    'instruction too long' => [['instruction' => str_repeat('а', 1001)], 'instruction', 'Инструкция для пациента — не больше 1000 символов.'],
    'no start' => [['starts_on' => null], 'starts_on', 'Укажите дату начала.'],
    'start in another format' => [['starts_on' => '19.10.2026'], 'starts_on', 'Укажите дату начала.'],
    'start yesterday' => [['starts_on' => '2026-10-18'], 'starts_on', 'Дата начала не может быть раньше сегодняшнего дня по времени пациента.'],
    'end before start' => [['starts_on' => '2026-10-25', 'ends_on' => '2026-10-24'], 'ends_on', 'Дата окончания не может быть раньше даты начала.'],
    'end in another format' => [['ends_on' => '01.11.2026'], 'ends_on', 'Проверьте дату окончания.'],
]);

test('drug or strength removed from the catalog can not be prescribed', function () {
    $removed = catalogDrug('Агомелатин', attributes: ['is_active' => false]);
    $sertraline = catalogDrug('Сертралин', [50 => true]);
    $removedStrength = DrugStrength::factory()->for($sertraline)->inactive()->create(['mg' => 25]);
    $other = catalogDrug('Гидроксизин');

    $this->actingAs($this->doctor);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($removed))
        ->assertSessionHasErrors(['drug_id' => 'Выберите препарат из справочника.']);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($sertraline, [
        'drug_strength_id' => $removedStrength->id,
    ]))->assertSessionHasErrors(['drug_strength_id' => 'Выберите дозировку из справочника.']);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($sertraline, [
        'drug_strength_id' => $other->strengths()->value('id'),
    ]))->assertSessionHasErrors(['drug_strength_id' => 'Выберите дозировку из справочника.']);

    expect(Prescription::query()->count())->toBe(0);
});

test('start date is counted in the patient timezone', function (RussianTimezone $timezone, string $today) {
    // 21:30 UTC: в Калининграде ещё 19 октября, на Камчатке уже 20-е.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30', 'UTC'));
    $this->patient->patientProfile?->update(['timezone' => $timezone]);
    $hydroxyzine = catalogDrug('Гидроксизин');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.create', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('today', $today));

    $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['starts_on' => $yesterday]))
        ->assertSessionHasErrors(['starts_on' => 'Дата начала не может быть раньше сегодняшнего дня по времени пациента.']);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['starts_on' => $today]))
        ->assertSessionHasNoErrors();

    expect(Prescription::query()->sole()->starts_on->toDateString())->toBe($today);
})->with([
    'Kaliningrad' => [RussianTimezone::Kaliningrad, '2026-10-19'],
    'Kamchatka' => [RussianTimezone::Kamchatka, '2026-10-20'],
]);

test('second regular prescription of the same drug is rejected', function (array $overrides) {
    $hydroxyzine = catalogDrug('Гидроксизин');
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['ends_on' => '2026-10-31']));

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, $overrides))
        ->assertSessionHasErrors(['drug_id' => 'У пациента уже есть регулярное назначение этого препарата на эти даты. Измените или отмените его.']);

    expect(Prescription::query()->count())->toBe(1);
})->with([
    'from the same day' => [[]],
    'with another dose' => [['intakes' => [['time_of_day' => 'evening', 'tablets' => 2]]]],
    'starting later inside the period' => [['starts_on' => '2026-10-25']],
    'starting on the last day' => [['starts_on' => '2026-10-31']],
]);

test('second prescription of the same drug as needed is rejected', function () {
    $hydroxyzine = catalogDrug('Гидроксизин');
    $asNeeded = ['kind' => 'as_needed', 'tablets' => 1, 'max_daily_doses' => 2];
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, $asNeeded));

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, [...$asNeeded, 'starts_on' => '2026-12-01']))
        ->assertSessionHasErrors(['drug_id' => 'У пациента уже есть назначение этого препарата «по необходимости» на эти даты. Измените или отмените его.']);
});

test('prescriptions of the same drug that do not overlap are allowed', function () {
    $hydroxyzine = catalogDrug('Гидроксизин');
    $other = User::factory()->patientOf($this->doctor)->create();
    $this->actingAs($this->doctor);

    // Регулярно до конца месяца.
    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['ends_on' => '2026-10-31']))
        ->assertSessionHasNoErrors();
    // Регулярно со следующего дня после окончания.
    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, ['starts_on' => '2026-11-01']))
        ->assertSessionHasNoErrors();
    // «По необходимости» одновременно с регулярным.
    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine, [
        'kind' => 'as_needed',
        'tablets' => 1,
        'max_daily_doses' => 2,
    ]))->assertSessionHasNoErrors();
    // Тот же препарат другому пациенту.
    $this->post(route('doctor.patients.prescriptions.store', $other), prescriptionPayload($hydroxyzine))
        ->assertSessionHasNoErrors();

    expect(Prescription::query()->count())->toBe(4);
});

test('cancelled or completed prescription does not block a new one', function () {
    $hydroxyzine = catalogDrug('Гидроксизин');
    $strength = $hydroxyzine->strengths()->sole();
    Prescription::factory()->for($this->patient, 'patient')->for($strength, 'strength')->cancelled()->create();
    Prescription::factory()->for($this->patient, 'patient')->for($strength, 'strength')->create([
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-18',
    ]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($hydroxyzine))
        ->assertSessionHasNoErrors();

    expect(Prescription::query()->count())->toBe(3);
});
