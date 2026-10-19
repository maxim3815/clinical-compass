<?php

use App\Actions\SyncDoseEvents;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionSource;
use App\Enums\RussianTimezone;
use App\Models\DoseEvent;
use App\Models\DrugStrength;
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
    $this->strength = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
});

/**
 * Время приёмов назначения: время суток => «08:00» или null.
 *
 * @return array<string, string|null>
 */
function intakeTimesOf(Prescription $prescription): array
{
    return PrescriptionIntake::sortByTimeOfDay($prescription->intakes()->get())
        ->mapWithKeys(fn (PrescriptionIntake $intake) => [$intake->time_of_day->value => $intake->time])
        ->all();
}

test('the form is filled with hints for intakes without time', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['bedtime' => '1', 'morning' => '0.5', 'afternoon' => '1', 'evening' => '1']);

    $this->actingAs($this->patient)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/IntakeTimes')
            ->where('prescription.id', $prescription->id)
            ->where('prescription.title', 'Гидроксизин, 25 мг')
            ->where('prescription.intakes', [
                ['time_of_day' => 'morning', 'label' => 'Утром', 'dose' => '½ таблетки, 12,5 мг', 'time' => '08:00'],
                ['time_of_day' => 'afternoon', 'label' => 'Днём', 'dose' => '1 таблетка, 25 мг', 'time' => '14:00'],
                ['time_of_day' => 'evening', 'label' => 'Вечером', 'dose' => '1 таблетка, 25 мг', 'time' => '20:00'],
                ['time_of_day' => 'bedtime', 'label' => 'Перед сном', 'dose' => '1 таблетка, 25 мг', 'time' => '22:00'],
            ])
            ->where('prescription.has_times', false)
            ->where('timezone', 'Москва (UTC+3)'),
        );
});

test('the form shows the chosen time', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '07:30']);
    PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => 'evening']);

    $this->actingAs($this->patient)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescription.intakes.0.time', '07:30')
            ->where('prescription.intakes.1.time', '20:00')
            ->where('prescription.has_times', false),
        );

    $prescription->intakes()->where('time_of_day', 'evening')->update(['time' => '21:15', 'time_set_at' => now()]);

    $this->actingAs($this->patient)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescription.intakes.1.time', '21:15')
            ->where('prescription.has_times', true),
        );
});

test('patient chooses the time and dose events are planned in their timezone', function () {
    $this->patient->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1', 'evening' => '1'], ['starts_on' => '2026-10-19']);

    // На Камчатке сейчас 21:00 19 октября: вечерний приём в 21:30 ещё впереди.
    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '08:00', 'evening' => '21:30']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Время приёма сохранено.']);

    expect(intakeTimesOf($prescription))->toBe(['morning' => '08:00', 'evening' => '21:30'])
        ->and($prescription->intakes()->get()->every(fn (PrescriptionIntake $intake) => $intake->time_set_at?->eq(now())))->toBeTrue()
        ->and(doseSchedule($this->patient))->toBe([
            '2026-10-19 evening 2026-10-19 09:30',
            '2026-10-20 morning 2026-10-19 20:00',
            '2026-10-20 evening 2026-10-20 09:30',
        ]);
});

test('changing the time moves future dose events and keeps the moment for unchanged intakes', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '08:00', 'evening' => '21:00']])
        ->assertRedirect(route('patient.medications'));

    $morning = $prescription->intakes()->where('time_of_day', 'morning')->sole();
    $evening = $prescription->intakes()->where('time_of_day', 'evening')->sole();

    expect($morning->time_set_at?->eq(now()->subWeek()))->toBeTrue()
        ->and($evening->time_set_at?->eq(now()))->toBeTrue()
        ->and(doseSchedule($this->patient))->toBe([
            '2026-10-19 morning 2026-10-19 05:00',
            '2026-10-19 evening 2026-10-19 18:00',
            '2026-10-20 morning 2026-10-20 05:00',
            '2026-10-20 evening 2026-10-20 18:00',
        ]);
});

test('saving the same time changes nothing', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00'], ['effective_at' => now()->subHour()]);

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '08:00']])
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Изменений нет, время осталось прежним.']);

    expect($prescription->intakes()->sole()->time_set_at?->eq(now()->subHour()))->toBeTrue()
        ->and(DoseEvent::query()->count())->toBe(0);
});

test('patient chooses the time of an upcoming prescription', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-20']);

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '09:00']])
        ->assertRedirect(route('patient.medications'));

    expect(doseSchedule($this->patient))->toBe(['2026-10-20 morning 2026-10-20 06:00']);
});

test('times are validated', function (array $times, string $field, string $message) {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1', 'evening' => '1']);

    $this->actingAs($this->patient)
        ->from(route('patient.intake-times.edit', $prescription))
        ->put(route('patient.intake-times.update', $prescription), ['times' => $times])
        ->assertRedirect(route('patient.intake-times.edit', $prescription))
        ->assertSessionHasErrors([$field => $message]);

    expect(intakeTimesOf($prescription))->toBe(['morning' => null, 'evening' => null]);
})->with([
    'missing' => [['morning' => '08:00'], 'times.evening', 'Укажите время приёма.'],
    'empty' => [['morning' => '08:00', 'evening' => ''], 'times.evening', 'Укажите время приёма.'],
    'not a time' => [['morning' => '8 утра', 'evening' => '20:00'], 'times.morning', 'Укажите время в формате ЧЧ:ММ.'],
    'out of range' => [['morning' => '08:00', 'evening' => '25:00'], 'times.evening', 'Укажите время в формате ЧЧ:ММ.'],
    'the same time' => [['morning' => '20:00', 'evening' => '20:00'], 'times.evening', 'Время совпадает с другим приёмом этого препарата.'],
    'unknown intake' => [['morning' => '08:00', 'evening' => '20:00', 'bedtime' => '22:00'], 'times', 'Время приёма указано неверно.'],
]);

test('times must be an object of intakes', function () {
    $prescription = regularPrescription($this->patient, $this->strength);

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => '08:00'])
        ->assertSessionHasErrors(['times' => 'Время приёма указано неверно.']);
});

test('closed or completed prescriptions send the patient back to the medications', function (array $attributes) {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], $attributes);

    $this->actingAs($this->patient)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Назначение уже изменилось или закончилось. Проверьте актуальные назначения.']);

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '08:00']])
        ->assertRedirect(route('patient.medications'));

    expect($prescription->intakes()->sole()->time)->toBeNull();
})->with([
    'changed' => [['closed_at' => '2026-10-19 08:00', 'close_reason' => PrescriptionCloseReason::Changed]],
    'cancelled' => [['closed_at' => '2026-10-19 08:00', 'close_reason' => PrescriptionCloseReason::Cancelled]],
    'completed' => [['starts_on' => '2026-10-01', 'ends_on' => '2026-10-18']],
]);

test('patient can not choose the time of other prescriptions', function (Closure $prescription) {
    $prescription = $prescription($this->patient, $this->strength);

    $this->actingAs($this->patient)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertNotFound();

    $this->actingAs($this->patient)
        ->put(route('patient.intake-times.update', $prescription), ['times' => ['morning' => '08:00']])
        ->assertNotFound();
})->with([
    'of another patient' => fn (User $patient, DrugStrength $strength) => regularPrescription(User::factory()->patient()->create(), $strength),
    'as needed' => fn (User $patient, DrugStrength $strength) => Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->asNeeded()->create(),
    'added by the patient' => fn (User $patient, DrugStrength $strength) => regularPrescription($patient, $strength, attributes: ['source' => PrescriptionSource::Patient]),
]);

test('doctors can not open the patient time form', function () {
    $prescription = regularPrescription($this->patient, $this->strength);

    $this->actingAs($this->doctor)
        ->get(route('patient.intake-times.edit', $prescription))
        ->assertForbidden();
});

test('non numeric prescription id is not found', function () {
    $this->actingAs($this->patient)
        ->get('/patient/medications/abc/times')
        ->assertNotFound();
});
