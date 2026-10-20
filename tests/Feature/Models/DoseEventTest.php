<?php

use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\AsNeededDose;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('database keeps one dose event of an intake a day with an open window', function () {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();
    DoseEvent::factory()->for($prescription)->create(['time_of_day' => 'morning', 'local_date' => '2026-10-19']);

    // Ошибка прерывает транзакцию теста, поэтому каждый запрос идёт в точке сохранения.
    expect(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['time_of_day' => 'morning', 'local_date' => '2026-10-19'])))
        ->toThrow(QueryException::class, 'dose_events_prescription_id_time_of_day_local_date_unique')
        ->and(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['local_date' => '2026-10-20', 'window_closes_at' => now()->addHour()])))
        ->toThrow(QueryException::class, 'dose_events_window_check')
        ->and(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['local_date' => '2026-10-21', 'tablets' => '0.3'])))
        ->toThrow(QueryException::class, 'dose_events_tablets_check');
});

test('database keeps the mark of a dose event consistent with its status', function (array $attributes) {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();

    expect(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create($attributes)))
        ->toThrow(QueryException::class, 'dose_events_mark_check');
})->with([
    'taken without the time of the intake' => [['status' => DoseEventStatus::Taken, 'marked_at' => now()]],
    'taken without the time of the mark' => [['status' => DoseEventStatus::Taken, 'taken_at' => now()]],
    'skipped without a reason' => [['status' => DoseEventStatus::Skipped, 'marked_at' => now()]],
    'skipped for another reason without a comment' => [['status' => DoseEventStatus::Skipped, 'marked_at' => now(), 'skip_reason' => DoseSkipReason::Other]],
    'scheduled with a mark' => [['marked_at' => now()]],
    'missed with a time of the intake' => [['status' => DoseEventStatus::Missed, 'taken_at' => now()]],
    'taken with a reason' => [['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now(), 'skip_reason' => DoseSkipReason::Forgot]],
    'comment without a reason' => [['skip_comment' => 'Был в дороге']],
]);

test('database keeps whole and half tablets of as-needed doses', function () {
    expect(fn () => DB::transaction(fn () => AsNeededDose::factory()->create(['tablets' => '0.3'])))
        ->toThrow(QueryException::class, 'as_needed_doses_tablets_check');
});

test('database keeps the time of an intake together with the moment it was chosen', function () {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();

    expect(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time' => '08:00'])))
        ->toThrow(QueryException::class, 'prescription_intakes_time_check')
        ->and(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time_set_at' => now()])))
        ->toThrow(QueryException::class, 'prescription_intakes_time_check');
});

test('intake time is read without seconds', function () {
    $intake = PrescriptionIntake::factory()->at('07:05')->create();

    expect($intake->fresh()?->time)->toBe('07:05');
});

test('a reminder is due at the time of the dose and at two repeats, each once and not late', function (string $now, ?string $remindedAt, bool $due) {
    $at = fn (string $time) => CarbonImmutable::parse('2026-10-19 '.$time, 'UTC');
    $dose = new DoseEvent([
        'status' => DoseEventStatus::Scheduled,
        'scheduled_at' => $at('05:00'),
        'window_closes_at' => $at('17:00'),
        'reminded_at' => $remindedAt === null ? null : $at($remindedAt),
    ]);

    expect($dose->isReminderDue($at($now)))->toBe($due);
})->with([
    'before the time' => ['04:59', null, false],
    'at the time' => ['05:00', null, true],
    'already reminded' => ['05:03', '05:00', false],
    'first reminder is late' => ['05:11', null, false],
    'first repeat' => ['05:30', '05:00', true],
    'first repeat after a late start' => ['05:30', null, true],
    'second repeat' => ['06:00', '05:30', true],
    'no third repeat' => ['06:30', '06:00', false],
    'nothing later in the window' => ['16:00', '06:00', false],
]);

test('a snoozed dose is reminded when the snooze ends and not before', function () {
    $at = fn (string $time) => CarbonImmutable::parse('2026-10-19 '.$time, 'UTC');
    $dose = new DoseEvent([
        'status' => DoseEventStatus::Scheduled,
        'scheduled_at' => $at('05:00'),
        'window_closes_at' => $at('17:00'),
        'reminded_at' => $at('06:00'),
        'snoozed_until' => $at('07:15'),
    ]);

    expect($dose->isReminderDue($at('07:14')))->toBeFalse()
        ->and($dose->isReminderDue($at('07:15')))->toBeTrue()
        ->and($dose->isReminderDue($at('07:26')))->toBeFalse();

    // Пока приём отложен, обычный повтор ждёт.
    $dose->fill(['reminded_at' => $at('05:00'), 'snoozed_until' => $at('05:40')]);
    expect($dose->isReminderDue($at('05:30')))->toBeFalse()
        ->and($dose->isReminderDue($at('05:40')))->toBeTrue();

    // Окно закрылось: отсрочка больше не напоминает.
    $dose->fill(['snoozed_until' => $at('17:05')]);
    expect($dose->isReminderDue($at('17:05')))->toBeFalse();
});

test('marked and missed doses are not reminded', function (DoseEventStatus $status) {
    $dose = new DoseEvent([
        'status' => $status,
        'scheduled_at' => now(),
        'window_closes_at' => now()->addHours(12),
    ]);

    expect($dose->isReminderDue(CarbonImmutable::now()))->toBeFalse();
})->with([DoseEventStatus::Taken, DoseEventStatus::Skipped, DoseEventStatus::Missed]);
