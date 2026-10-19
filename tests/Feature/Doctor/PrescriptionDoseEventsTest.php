<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
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
    $this->hydroxyzine = catalogDrug('Гидроксизин', [25 => true]);
    $this->strength = $this->hydroxyzine->strengths()->sole();
    $this->weekAgo = ['effective_at' => now()->subWeek(), 'starts_on' => '2026-10-12'];
});

/**
 * Плановые приёмы версии назначения: «2026-10-19 evening 1.0».
 *
 * @return list<string>
 */
function doseEventsOf(Prescription $prescription): array
{
    return $prescription->doseEvents()
        ->orderBy('scheduled_at')
        ->get()
        ->map(fn (DoseEvent $event) => $event->local_date->toDateString().' '.$event->time_of_day->value.' '.$event->tablets)
        ->all();
}

test('a changed prescription keeps the time of intakes with the same time of day', function () {
    $old = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $old]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [
                ['time_of_day' => 'morning', 'tablets' => 1],
                ['time_of_day' => 'evening', 'tablets' => 2],
                ['time_of_day' => 'bedtime', 'tablets' => 1],
            ],
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    $new = Prescription::query()->where('previous_id', $old->id)->sole();
    $times = PrescriptionIntake::sortByTimeOfDay($new->intakes)
        ->map(fn (PrescriptionIntake $intake) => [$intake->time_of_day->value, $intake->time, $intake->time_set_at?->toDateTimeString()])
        ->all();

    expect($new->effective_at->eq(now()))->toBeTrue()
        ->and($times)->toBe([
            ['morning', '08:00', '2026-10-12 09:00:00'],
            ['evening', '20:00', '2026-10-12 09:00:00'],
            ['bedtime', null, null],
        ])
        // Прошедший утренний приём остаётся у прежней версии, будущие переходят к новой.
        ->and(doseEventsOf($old))->toBe(['2026-10-19 morning 1.0'])
        ->and(doseEventsOf($new))->toBe([
            '2026-10-19 evening 2.0',
            '2026-10-20 morning 1.0',
            '2026-10-20 evening 2.0',
        ]);

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.0.intakes', [
                ['time_of_day' => 'Утром', 'dose' => '1 таблетка, 25 мг', 'time' => '8:00'],
                ['time_of_day' => 'Вечером', 'dose' => '2 таблетки, 50 мг', 'time' => '20:00'],
                ['time_of_day' => 'Перед сном', 'dose' => '1 таблетка, 25 мг', 'time' => null],
            ])
            ->where('prescriptionHistory.0.intakes', [
                ['time_of_day' => 'Утром', 'dose' => '1 таблетка, 25 мг', 'time' => '8:00'],
                ['time_of_day' => 'Вечером', 'dose' => '1 таблетка, 25 мг', 'time' => '20:00'],
            ]),
        );
});

test('a dose marked in advance is not planned again after a change', function () {
    // 19:30 по Москве: вечерний приём в 20:00 пациент уже отметил, затем врач меняет назначение.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 16:30', 'UTC'));
    $old = timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);
    $old->doseEvents()->where('local_date', '2026-10-19')->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $old]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [['time_of_day' => 'evening', 'tablets' => 2]],
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    $new = Prescription::query()->where('previous_id', $old->id)->sole();

    expect(doseEventsOf($old))->toBe(['2026-10-19 evening 1.0'])
        ->and(doseEventsOf($new))->toBe(['2026-10-20 evening 2.0']);
});

test('editing a prescription that has not started updates its dose events', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], ['starts_on' => '2026-10-20']);
    app(SyncDoseEvents::class)->handle($this->patient);
    $morning = $prescription->doseEvents()->where('time_of_day', 'morning')->sole();

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [['time_of_day' => 'morning', 'tablets' => 0.5], ['time_of_day' => 'bedtime', 'tablets' => 1]],
            'starts_on' => '2026-10-20',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    expect(doseEventsOf($prescription))->toBe(['2026-10-20 morning 0.5'])
        ->and($prescription->doseEvents()->sole()->id)->toBe($morning->id);
});

test('a prescription moved to start today gets no dose events before the edit', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-25',
        'effective_at' => now()->subWeek(),
    ]);

    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $prescription]), prescriptionPayload($this->hydroxyzine, [
            'intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 1]],
            'starts_on' => '2026-10-19',
        ]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    expect(doseEventsOf($prescription))->toBe([
        '2026-10-19 evening 1.0',
        '2026-10-20 morning 1.0',
        '2026-10-20 evening 1.0',
    ]);
});

test('cancelling a prescription deletes its future unmarked dose events', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'afternoon' => '13:00', 'evening' => '20:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);
    $prescription->doseEvents()->where('local_date', '2026-10-19')->where('time_of_day', 'afternoon')->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]))
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    expect(doseEventsOf($prescription))->toBe([
        '2026-10-19 morning 1.0',
        '2026-10-19 afternoon 1.0',
    ]);
});
