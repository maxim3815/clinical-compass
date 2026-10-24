<?php

use App\Enums\Sex;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Icd10Code;
use App\Models\ObservationType;
use App\Models\User;
use App\Support\DisplayTime;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->today = DisplayTime::today()->toDateString();

    Icd10Code::query()->insert([
        ['code' => 'F41.1', 'name' => 'Генерализованное тревожное расстройство'],
        ['code' => 'F45.3', 'name' => 'Соматоформная дисфункция вегетативной нервной системы'],
        ['code' => 'F32.1', 'name' => 'Депрессивный эпизод средней степени'],
    ]);
});

test('doctor sets sex, diagnoses and facts and the card shows the current values', function () {
    $edit = route('doctor.patients.clinical-profile.edit', $this->patient);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.clinical-profile.sex', $this->patient), ['sex' => 'female'])
        ->assertRedirect($edit);
    $this->post(route('doctor.patients.diagnoses.store', $this->patient), ['icd10_code' => 'F41.1', 'is_main' => true, 'established_on' => $this->today])
        ->assertRedirect($edit);
    $this->post(route('doctor.patients.diagnoses.store', $this->patient), ['icd10_code' => 'F45.3', 'is_main' => false, 'established_on' => $this->today]);

    $gfr = ObservationType::query()->where('code', 'gfr')->sole();
    $observe = fn (array $data) => $this->post(route('doctor.patients.observations.store', $this->patient), ['observations' => [['observation_type_id' => $gfr->id, 'observed_on' => $this->today, ...$data]]]);
    $observe(['value' => '58'])->assertRedirect($edit)->assertSessionHasNoErrors();
    $observe(['value' => '52', 'comment' => 'CKD-EPI'])->assertSessionHasNoErrors();

    expect($this->patient->patientProfile()->first()?->sex)->toBe(Sex::Female)
        ->and($this->patient->observations()->count())->toBe(2);

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('clinicalProfile.sex_label', 'Женский')
            ->where('clinicalProfile.diagnoses.0.code', 'F41.1')
            ->where('clinicalProfile.diagnoses.0.is_main', true)
            ->where('clinicalProfile.diagnoses.1.code', 'F45.3')
            ->has('clinicalProfile.facts', 1)
            ->where('clinicalProfile.facts.0.value', '52 мл/мин/1,73 м²')
            ->where('clinicalProfile.facts.0.comment', 'CKD-EPI')
            ->where('clinicalProfile.facts.0.recorded_by', 'Смирнова Анна Сергеевна'),
        );

    $this->get($edit)
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/clinical-profile/Edit')
            ->where('profile.types.2.code', 'gfr')
            ->has('profile.types.2.history', 2)
            ->where('profile.types.2.history.1.value', '58 мл/мин/1,73 м²'),
        );

    expect(AuditLog::query()->where('patient_id', $this->patient->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['patient.sex_changed', 'diagnosis.added', 'diagnosis.added', 'observation.recorded', 'observation.recorded']);
});

test('diagnosis is resolved with a date and stays in the history', function () {
    $diagnosis = diagnose($this->patient, 'F32.1');

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.diagnoses.resolve', [$this->patient, $diagnosis]), ['resolved_on' => $this->today])
        ->assertSessionHasNoErrors();

    expect($diagnosis->fresh()?->resolved_on?->toDateString())->toBe($this->today)
        ->and($diagnosis->fresh()?->resolved_by_id)->toBe($this->doctor->id);

    $this->get(route('doctor.patients.clinical-profile.edit', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->has('profile.diagnoses', 0)
            ->where('profile.resolved_diagnoses.0.code', 'F32.1')
            ->where('profile.resolved_diagnoses.0.resolved_by', 'Смирнова Анна Сергеевна'),
        );

    $this->post(route('doctor.patients.diagnoses.resolve', [$this->patient, $diagnosis]), ['resolved_on' => $this->today])
        ->assertSessionHasErrors(['resolved_on' => 'Диагноз уже снят.']);
});

test('profile input is validated', function (Closure $request, string $field, string $message) {
    diagnose($this->patient, 'F41.1');
    $this->actingAs($this->doctor);

    $request($this)->assertSessionHasErrors([$field => $message]);
})->with([
    'second main diagnosis' => [fn ($test) => $test->post(route('doctor.patients.diagnoses.store', $test->patient), ['icd10_code' => 'F45.3', 'is_main' => true, 'established_on' => $test->today]), 'is_main', 'Основной диагноз уже есть: F41.1 Генерализованное тревожное расстройство. Снимите его или отметьте новый как сопутствующий.'],
    'same diagnosis twice' => [fn ($test) => $test->post(route('doctor.patients.diagnoses.store', $test->patient), ['icd10_code' => 'F41.1', 'is_main' => false, 'established_on' => $test->today]), 'icd10_code', 'Этот диагноз у пациента уже стоит.'],
    'unknown code' => [fn ($test) => $test->post(route('doctor.patients.diagnoses.store', $test->patient), ['icd10_code' => 'X99.9', 'is_main' => false, 'established_on' => $test->today]), 'icd10_code', 'Выберите диагноз из справочника МКБ-10.'],
    'future diagnosis' => [fn ($test) => $test->post(route('doctor.patients.diagnoses.store', $test->patient), ['icd10_code' => 'F45.3', 'is_main' => false, 'established_on' => DisplayTime::today()->addDay()->toDateString()]), 'established_on', 'Дата установления не может быть позже сегодняшнего дня.'],
    'number out of range' => [fn ($test) => $test->post(route('doctor.patients.observations.store', $test->patient), ['observations' => [['observation_type_id' => ObservationType::query()->where('code', 'gfr')->value('id'), 'value' => '520', 'observed_on' => $test->today]]]), 'observations.0.value', 'Значение не больше 200.'],
    'unknown option' => [fn ($test) => $test->post(route('doctor.patients.observations.store', $test->patient), ['observations' => [['observation_type_id' => ObservationType::query()->where('code', 'hepatic_impairment')->value('id'), 'value' => 'd', 'observed_on' => $test->today]]]), 'observations.0.value', 'Укажите значение.'],
    'no facts' => [fn ($test) => $test->post(route('doctor.patients.observations.store', $test->patient), ['observations' => []]), 'observations', 'Выберите хотя бы одно значение.'],
    'same fact twice' => [fn ($test) => $test->post(route('doctor.patients.observations.store', $test->patient), ['observations' => array_fill(0, 2, ['observation_type_id' => ObservationType::query()->where('code', 'gfr')->value('id'), 'value' => '50', 'observed_on' => $test->today])]), 'observations.1.observation_type_id', 'Один показатель внесён дважды.'],
    'unknown sex' => [fn ($test) => $test->put(route('doctor.patients.clinical-profile.sex', $test->patient), ['sex' => 'other']), 'sex', 'Выберите пол из списка.'],
]);

test('only the treating doctor sees and changes the profile', function () {
    $other = User::factory()->doctor()->create();
    $diagnosis = diagnose($this->patient, 'F41.1');
    $gfr = ObservationType::query()->where('code', 'gfr')->value('id');

    $requests = [
        fn () => $this->get(route('doctor.patients.clinical-profile.edit', $this->patient)),
        fn () => $this->put(route('doctor.patients.clinical-profile.sex', $this->patient), ['sex' => 'male']),
        fn () => $this->post(route('doctor.patients.diagnoses.store', $this->patient), ['icd10_code' => 'F45.3', 'is_main' => false, 'established_on' => $this->today]),
        fn () => $this->post(route('doctor.patients.diagnoses.resolve', [$this->patient, $diagnosis]), ['resolved_on' => $this->today]),
        fn () => $this->post(route('doctor.patients.observations.store', $this->patient), ['observations' => [['observation_type_id' => $gfr, 'value' => '50', 'observed_on' => $this->today]]]),
    ];

    foreach ($requests as $request) {
        $this->actingAs($other);
        $request()->assertNotFound();
        $this->actingAs($this->patient);
        $request()->assertForbidden();
    }

    expect($this->patient->observations()->count())->toBe(0)
        ->and(Diagnosis::query()->whereNotNull('resolved_on')->exists())->toBeFalse()
        ->and($this->patient->patientProfile()->first()?->sex)->toBeNull();
});

test('diagnosis of another patient can not be resolved through this patient', function () {
    $other = User::factory()->patientOf($this->doctor)->create();
    $diagnosis = diagnose($other, 'F41.1');

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.diagnoses.resolve', [$this->patient, $diagnosis]), ['resolved_on' => $this->today])
        ->assertNotFound();
});

test('icd10 search finds codes by code and by name', function () {
    $this->actingAs($this->doctor)
        ->getJson(route('doctor.icd10.index', ['search' => 'f41']))
        ->assertOk()
        ->assertJsonPath('codes.0.code', 'F41.1');

    $this->getJson(route('doctor.icd10.index', ['search' => 'соматоформ']))
        ->assertJsonPath('codes.0.code', 'F45.3');

    $this->getJson(route('doctor.icd10.index', ['search' => 'F']))->assertExactJson(['codes' => []]);

    $this->actingAs($this->patient)->getJson(route('doctor.icd10.index', ['search' => 'F41']))->assertForbidden();
});

test('several facts are saved at once as separate records, or none if one is wrong', function () {
    $this->patient->patientProfile?->update(['sex' => Sex::Female]);
    $type = fn (string $code): int => ObservationType::query()->where('code', $code)->value('id');
    $this->actingAs($this->doctor);

    $this->post(route('doctor.patients.observations.store', $this->patient), ['observations' => [
        ['observation_type_id' => $type('pregnancy'), 'value' => '1', 'comment' => ' 7 недель ', 'observed_on' => $this->today],
        ['observation_type_id' => $type('epilepsy'), 'value' => '0', 'observed_on' => $this->today],
        ['observation_type_id' => $type('gfr'), 'value' => '520', 'observed_on' => $this->today],
    ]])->assertSessionHasErrors(['observations.2.value' => 'Значение не больше 200.']);

    expect($this->patient->observations()->count())->toBe(0);

    $this->post(route('doctor.patients.observations.store', $this->patient), ['observations' => [
        ['observation_type_id' => $type('pregnancy'), 'value' => '1', 'comment' => ' 7 недель ', 'observed_on' => $this->today],
        ['observation_type_id' => $type('epilepsy'), 'value' => '0', 'observed_on' => $this->today],
    ]])->assertSessionHasNoErrors()->assertRedirect(route('doctor.patients.clinical-profile.edit', $this->patient));

    $observations = $this->patient->observations()->with('type')->orderBy('id')->get();

    expect($observations->map(fn ($observation) => [$observation->type->code, $observation->value(), $observation->comment])->all())
        ->toBe([['pregnancy', true, '7 недель'], ['epilepsy', false, null]])
        ->and(AuditLog::query()->where('action', 'observation.recorded')->count())->toBe(2);
});
