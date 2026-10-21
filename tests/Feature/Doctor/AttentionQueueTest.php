<?php

use App\Actions\SendMessage;
use App\Actions\SyncSafetyFindings;
use App\Enums\Sex;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве, 20 октября.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create();
    $this->aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
});

/**
 * Пациент врача с утренним курсом с 1 октября: приём принят, а последние два пропущены.
 */
function patientWithMisses(User $doctor, mixed $strength, string $name): User
{
    $patient = User::factory()->patientOf($doctor)->create(['name' => $name]);
    $course = adherenceCourse($doctor, $patient, $strength, '2026-10-01', times: ['morning' => '08:00']);
    adherenceDose($course, '2026-10-17');
    adherenceDose($course, '2026-10-18', 'missed');
    adherenceDose($course, '2026-10-19', 'missed');

    return $patient;
}

/**
 * Пациентка, у которой эсциталопрам с гидроксизином дают противопоказание без решения.
 */
function patientWithContraindication(User $doctor, string $name): User
{
    $patient = User::factory()->patientOf($doctor)->create(['name' => $name]);
    $patient->patientProfile?->update(['sex' => Sex::Female]);
    takes($patient, 'escitalopram', own: true);
    takes($patient, 'hydroxyzine', own: true);
    app(SyncSafetyFindings::class)->handle($patient);

    return $patient;
}

/**
 * @return list<array<string, mixed>>
 */
function attentionOf(User $doctor, array $query = []): array
{
    return test()->actingAs($doctor)
        ->get(route('doctor.patients.index', $query))
        ->viewData('page')['props']['attention'];
}

test('the queue shows who needs attention and why', function () {
    $signal = patientWithMisses($this->doctor, $this->aviandr, 'Соколов Михаил Игоревич');
    diagnose($signal, 'F41.1');
    $contraindication = patientWithContraindication($this->doctor, 'Кузнецова Елена Викторовна');
    $writes = User::factory()->patientOf($this->doctor)->create(['name' => 'Белов Иван Максимович']);
    app(SendMessage::class)->handle(careBetween($this->doctor, $writes), $writes, 'Добрый день!');
    app(SendMessage::class)->handle(careBetween($this->doctor, $writes), $writes, 'Можно вопрос?');
    User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);

    $queue = attentionOf($this->doctor);

    expect(array_column($queue, 'name'))->toBe(['Кузнецова Елена Викторовна', 'Соколов Михаил Игоревич', 'Белов Иван Максимович'])
        ->and($queue[0])->toMatchArray([
            'id' => $contraindication->id,
            'contraindications' => ['Гидроксизин', 'Эсциталопрам'],
            'adherence' => null,
            'unread' => 0,
        ])
        ->and($queue[1])->toMatchArray([
            'id' => $signal->id,
            'diagnosis' => ['code' => 'F41.1', 'name' => 'Диагноз F41.1'],
            'contraindications' => [],
            'adherence' => ['share' => 33, 'low_share' => true, 'consecutive_misses' => 2, 'raised' => true],
            'new_misses' => true,
        ])
        ->and($queue[2])->toMatchArray(['id' => $writes->id, 'adherence' => null, 'unread' => 2]);
});

test('signals with misses the doctor has not seen go before the seen ones', function () {
    $seen = patientWithMisses($this->doctor, $this->aviandr, 'Антонов Антон Антонович');
    patientWithMisses($this->doctor, $this->aviandr, 'Борисов Борис Борисович');
    CareRelationship::query()->where('patient_id', $seen->id)->update(['adherence_seen_at' => now()]);

    $queue = attentionOf($this->doctor);

    expect(array_column($queue, 'name'))->toBe(['Борисов Борис Борисович', 'Антонов Антон Антонович'])
        ->and(array_column($queue, 'new_misses'))->toBe([true, false]);
});

test('the queue is empty without signals and while searching', function () {
    User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);

    expect(attentionOf($this->doctor))->toBe([]);

    patientWithMisses($this->doctor, $this->aviandr, 'Соколов Михаил Игоревич');

    expect(attentionOf($this->doctor))->toHaveCount(1)
        ->and(attentionOf($this->doctor, ['search' => 'Соколов']))->toBe([]);
});

test('the queue has only own patients with an active relationship', function () {
    $former = patientWithMisses($this->doctor, $this->aviandr, 'Соколов Михаил Игоревич');
    CareRelationship::query()->where('patient_id', $former->id)->update(['ended_at' => now()]);
    patientWithContraindication(User::factory()->doctor()->create(), 'Кузнецова Елена Викторовна');

    expect(attentionOf($this->doctor))->toBe([]);
});

test('patient rows show the main diagnosis and unread messages', function () {
    $patient = User::factory()->patientOf($this->doctor)->create();
    diagnose($patient, 'F45.3', main: false);
    diagnose($patient, 'F41.1');
    app(SendMessage::class)->handle(careBetween($this->doctor, $patient), $patient, 'Добрый день!');
    app(SendMessage::class)->handle(careBetween($this->doctor, $patient), $this->doctor, 'Здравствуйте!');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.data.0.diagnosis', ['code' => 'F41.1', 'name' => 'Диагноз F41.1'])
            ->where('patients.data.0.unread', 1),
        );
});
