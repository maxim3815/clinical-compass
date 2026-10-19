<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionCloseReason;
use App\Enums\RussianTimezone;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->patient = User::factory()->patient()->create();
    $this->strength = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
    $this->weekAgo = ['effective_at' => now()->subWeek(), 'starts_on' => '2026-10-12'];
    $this->sync = fn () => app(SyncDoseEvents::class)->handle($this->patient);
});

test('dose events for today and tomorrow follow the timezone of the patient', function (RussianTimezone $timezone, array $expected) {
    // 13:00 UTC: в Калининграде 15:00 19 октября, на Камчатке уже 01:00 20 октября.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 13:00', 'UTC'));
    $this->patient->patientProfile?->update(['timezone' => $timezone]);
    timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe($expected)
        ->and(DoseEvent::query()->pluck('status')->unique()->all())->toBe([DoseEventStatus::Scheduled]);
})->with([
    'Калининград, UTC+2' => [RussianTimezone::Kaliningrad, [
        '2026-10-19 morning 2026-10-19 06:00',
        '2026-10-19 evening 2026-10-19 18:00',
        '2026-10-20 morning 2026-10-20 06:00',
        '2026-10-20 evening 2026-10-20 18:00',
    ]],
    'Камчатка, UTC+12' => [RussianTimezone::Kamchatka, [
        '2026-10-20 morning 2026-10-19 20:00',
        '2026-10-20 evening 2026-10-20 08:00',
        '2026-10-21 morning 2026-10-20 20:00',
        '2026-10-21 evening 2026-10-21 08:00',
    ]],
]);

test('no dose events before the moment the time was chosen', function () {
    // Время выбрано сейчас, в 12:00 по Москве: утренний приём сегодня уже прошёл.
    timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00']);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 evening 2026-10-19 17:00',
        '2026-10-20 morning 2026-10-20 05:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ]);
});

test('no dose events before the version became effective', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);
    // Время выбрано неделю назад, а версия действует только с этого момента.
    $prescription->update(['effective_at' => now()]);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 evening 2026-10-19 17:00',
        '2026-10-20 morning 2026-10-20 05:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ]);
});

test('no dose events outside the prescription dates', function () {
    timedPrescription($this->patient, $this->strength, ['morning' => '08:00'], [...$this->weekAgo, 'ends_on' => '2026-10-19']);
    timedPrescription($this->patient, catalogDrug('Сертралин')->strengths()->sole(), ['evening' => '20:00'], [...$this->weekAgo, 'starts_on' => '2026-10-20']);
    timedPrescription($this->patient, catalogDrug('Пароксетин')->strengths()->sole(), ['evening' => '21:00'], [...$this->weekAgo, 'starts_on' => '2026-10-21']);
    timedPrescription($this->patient, catalogDrug('Эсциталопрам')->strengths()->sole(), ['evening' => '22:00'], [...$this->weekAgo, 'ends_on' => '2026-10-18']);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 morning 2026-10-19 05:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ]);
});

test('closed prescriptions, intakes without time and as needed prescriptions have no dose events', function () {
    timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], [...$this->weekAgo, 'closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);
    regularPrescription($this->patient, catalogDrug('Сертралин')->strengths()->sole(), ['morning' => '1'], $this->weekAgo);
    Prescription::factory()->for($this->patient, 'patient')->for(catalogDrug('Пароксетин')->strengths()->sole(), 'strength')->asNeeded()->create($this->weekAgo);
    timedPrescription(User::factory()->patient()->create(), $this->strength, ['evening' => '20:00'], $this->weekAgo);

    ($this->sync)();

    expect(DoseEvent::query()->count())->toBe(0);
});

test('a prescription without time for some intakes gets dose events for the others', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], $this->weekAgo);
    PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => 'morning']);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 evening 2026-10-19 17:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ]);
});

test('dose events remember the dose of the intake', function () {
    $prescription = Prescription::factory()->for($this->patient, 'patient')->for($this->strength, 'strength')->create($this->weekAgo);
    PrescriptionIntake::factory()->for($prescription)->at('20:00', now()->subWeek())->create(['time_of_day' => 'evening', 'tablets' => '1.5']);

    ($this->sync)();

    expect(DoseEvent::query()->pluck('tablets')->all())->toBe(['1.5', '1.5']);
});

test('repeated sync changes nothing', function () {
    timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);
    ($this->sync)();
    $before = DoseEvent::query()->orderBy('id')->get()->toArray();

    $this->travelTo(now()->addMinutes(5));
    ($this->sync)();

    expect(DoseEvent::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('the window closes after window_hours of the drug', function () {
    $strength = catalogDrug('Препарат с окном', [10 => true], ['window_hours' => 4])->strengths()->sole();
    timedPrescription($this->patient, $strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);

    ($this->sync)();

    DoseEvent::query()->get()->each(fn (DoseEvent $event) => expect($event->scheduled_at->diffInMinutes($event->window_closes_at))->toEqual(240));
});

test('by default the window closes halfway to the next intake but not later than 12 hours', function (array $times, array $windows) {
    timedPrescription($this->patient, $this->strength, $times, $this->weekAgo);

    ($this->sync)();

    $actual = DoseEvent::query()
        ->where('local_date', '2026-10-20')
        ->get()
        ->mapWithKeys(fn (DoseEvent $event) => [$event->time_of_day->value => (int) $event->scheduled_at->diffInMinutes($event->window_closes_at)])
        ->all();

    expect($actual)->toEqualCanonicalizing($windows);
})->with([
    'once a day' => [['morning' => '08:00'], ['morning' => 720]],
    '8:00 and 20:00' => [['morning' => '08:00', 'evening' => '20:00'], ['morning' => 360, 'evening' => 360]],
    '8:00 and 22:00' => [['morning' => '08:00', 'bedtime' => '22:00'], ['morning' => 420, 'bedtime' => 300]],
    'three times a day' => [['morning' => '08:00', 'afternoon' => '14:00', 'evening' => '20:00'], ['morning' => 180, 'afternoon' => 180, 'evening' => 360]],
    // Окно не короче интервала «вовремя» из конфига: иначе приём стал бы пропущенным раньше, чем опоздавшим.
    '8:00 and 8:30' => [['morning' => '08:00', 'afternoon' => '08:30'], ['morning' => 60, 'afternoon' => 705]],
]);

test('future unmarked dose events move when the time changes and past or marked ones stay', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'afternoon' => '13:00', 'evening' => '20:00'], $this->weekAgo);
    ($this->sync)();
    $past = DoseEvent::query()->where('local_date', '2026-10-19')->where('time_of_day', 'morning')->sole();
    $marked = DoseEvent::query()->where('local_date', '2026-10-19')->where('time_of_day', 'afternoon')->sole();
    $marked->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);

    $prescription->intakes->each(fn (PrescriptionIntake $intake) => $intake->update([
        'time' => ['morning' => '09:00', 'afternoon' => '14:00', 'evening' => '21:00'][$intake->time_of_day->value],
        'time_set_at' => now(),
    ]));
    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 morning 2026-10-19 05:00',
        '2026-10-19 afternoon 2026-10-19 10:00',
        '2026-10-19 evening 2026-10-19 18:00',
        '2026-10-20 morning 2026-10-20 06:00',
        '2026-10-20 afternoon 2026-10-20 11:00',
        '2026-10-20 evening 2026-10-20 18:00',
    ])
        ->and($past->fresh()?->status)->toBe(DoseEventStatus::Scheduled)
        ->and($marked->fresh()?->status)->toBe(DoseEventStatus::Taken);
});

test('a future dose event moved to an earlier time stays for the day even if the new time has passed', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], $this->weekAgo);
    ($this->sync)();

    // В 12:00 пациент переносит приём на 7:00: сегодняшний приём остаётся, его можно отметить.
    $prescription->intakes()->sole()->update(['time' => '07:00', 'time_set_at' => now()]);
    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 evening 2026-10-19 04:00',
        '2026-10-20 evening 2026-10-20 04:00',
    ]);
});

test('a dose of the same drug and time of day is not planned twice a day', function () {
    $previous = timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], [...$this->weekAgo, 'closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Changed]);
    DoseEvent::factory()->for($previous)->taken()->create([
        'time_of_day' => 'evening',
        'local_date' => '2026-10-19',
        'scheduled_at' => CarbonImmutable::parse('2026-10-19 17:00', 'UTC'),
        'window_closes_at' => CarbonImmutable::parse('2026-10-19 23:00', 'UTC'),
    ]);
    // Новая версия с тем же временем: сегодняшний вечерний приём уже отмечен у прежней.
    timedPrescription($this->patient, $this->strength, ['evening' => '20:00'], ['previous_id' => $previous->id, 'starts_on' => '2026-10-19']);

    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe([
        '2026-10-19 evening 2026-10-19 17:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ])
        ->and(DoseEvent::query()->where('local_date', '2026-10-19')->sole()->prescription_id)->toBe($previous->id);
});

test('future unmarked dose events of closed prescriptions are deleted', function () {
    $prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], $this->weekAgo);
    ($this->sync)();

    $prescription->update(['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);
    ($this->sync)();

    expect(doseSchedule($this->patient))->toBe(['2026-10-19 morning 2026-10-19 05:00']);
});

test('another drug added by the patient gets the default window and a catalog drug the window of the drug', function () {
    ownPrescription($this->patient, times: ['morning' => '08:00', 'evening' => '20:00'], attributes: $this->weekAgo);
    ownPrescription($this->patient, catalogDrug('Препарат с окном', [10 => true], ['window_hours' => 4]), ['afternoon' => '14:00'], $this->weekAgo);

    ($this->sync)();

    $actual = DoseEvent::query()
        ->where('local_date', '2026-10-20')
        ->with('prescription')
        ->get()
        ->mapWithKeys(fn (DoseEvent $event) => [$event->prescription->drugName().' '.$event->time_of_day->value => (int) $event->scheduled_at->diffInMinutes($event->window_closes_at)])
        ->all();

    expect($actual)->toEqualCanonicalizing([
        'Фенибут morning' => 360,
        'Фенибут evening' => 360,
        'Препарат с окном afternoon' => 240,
    ])
        ->and(DoseEvent::query()->whereNotNull('tablets')->exists())->toBeFalse();
});

test('a prescription of the doctor and a medication added by the patient of the same drug do not replace each other', function () {
    timedPrescription($this->patient, $this->strength, ['morning' => '08:00'], $this->weekAgo);
    ($this->sync)();
    // Пациент добавил тот же препарат, когда утренний приём по назначению врача уже прошёл.
    $own = ownPrescription($this->patient, $this->strength->drug, ['morning' => '12:30'], $this->weekAgo);

    ($this->sync)();

    expect($own->doseEvents()->pluck('local_date')->map(fn (CarbonImmutable $date) => $date->toDateString())->sort()->values()->all())
        ->toBe(['2026-10-19', '2026-10-20'])
        ->and(DoseEvent::query()->count())->toBe(4);
});
