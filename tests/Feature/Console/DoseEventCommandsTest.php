<?php

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionCloseReason;
use App\Enums\RussianTimezone;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->strength = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
});

test('hourly sync plans the next day for every patient', function () {
    $moscow = User::factory()->patient()->create();
    $kamchatka = User::factory()->patient()->create();
    $kamchatka->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    $attributes = ['starts_on' => '2026-10-12', 'effective_at' => now()->subWeek()];
    timedPrescription($moscow, $this->strength, ['evening' => '20:00'], $attributes);
    timedPrescription($kamchatka, $this->strength, ['morning' => '09:00'], $attributes);
    // Без приёмов с временем и с закрытым назначением планировать нечего.
    regularPrescription(User::factory()->patient()->create(), $this->strength, ['morning' => '1'], $attributes);
    timedPrescription(User::factory()->patient()->create(), $this->strength, ['morning' => '09:00'], [...$attributes, 'closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]);

    $this->artisan('app:dose-events:sync')
        ->expectsOutputToContain('Пациентов: 3.')
        ->assertSuccessful();

    expect(doseSchedule($moscow))->toBe([
        '2026-10-19 evening 2026-10-19 17:00',
        '2026-10-20 evening 2026-10-20 17:00',
    ])
        ->and(doseSchedule($kamchatka))->toBe([
            '2026-10-19 morning 2026-10-18 21:00',
            '2026-10-20 morning 2026-10-19 21:00',
        ]);

    // Наступили следующие сутки на Камчатке (полночь там в 12:00 UTC), в Москве ещё нет.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'UTC'));
    $this->artisan('app:dose-events:sync')->assertSuccessful();

    expect(doseSchedule($moscow))->toHaveCount(2)
        ->and(doseSchedule($kamchatka))->toBe([
            '2026-10-19 morning 2026-10-18 21:00',
            '2026-10-20 morning 2026-10-19 21:00',
            '2026-10-21 morning 2026-10-20 21:00',
        ]);
});

test('dose events become missed when the window closes without a mark', function () {
    $patient = User::factory()->patient()->create();
    $prescription = timedPrescription($patient, $this->strength, ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $taken = DoseEvent::factory()->for($prescription)->taken()->create([
        'time_of_day' => 'morning',
        'local_date' => '2026-10-18',
        'scheduled_at' => now()->subDay()->subHours(4),
        'window_closes_at' => now()->subDay()->subHours(2),
    ]);

    // Окно утреннего приёма (8:00 по Москве) закрывается в 14:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:59', 'UTC'));
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    expect(DoseEvent::query()->where('status', DoseEventStatus::Missed)->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'UTC'));
    $this->artisan('app:dose-events:mark-missed')
        ->expectsOutputToContain('Пропущенных приёмов: 1.')
        ->assertSuccessful();

    $statuses = DoseEvent::query()
        ->orderBy('scheduled_at')
        ->get()
        ->map(fn (DoseEvent $event) => $event->local_date->toDateString().' '.$event->time_of_day->value.' '.$event->status->value)
        ->all();

    expect($statuses)->toBe([
        '2026-10-18 morning taken',
        '2026-10-19 morning missed',
        '2026-10-19 evening scheduled',
        '2026-10-20 morning scheduled',
        '2026-10-20 evening scheduled',
    ])
        ->and($taken->fresh()?->status)->toBe(DoseEventStatus::Taken);
});

test('the scheduler syncs dose events hourly, marks missed ones and sends reminders every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [(string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command) => $event->expression]);

    expect($events->get('app:dose-events:sync'))->toBe('0 * * * *')
        ->and($events->get('app:dose-events:mark-missed'))->toBe('* * * * *')
        ->and($events->get('app:dose-events:send-reminders'))->toBe('* * * * *');
});
