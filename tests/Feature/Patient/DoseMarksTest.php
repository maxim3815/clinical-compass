<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Приёмы за вчера и сегодня: синхронизация вчера в 12:00 по Москве, сегодня в 12:00 и scheduler.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->aviandr = catalogDrug('Авиандр', [20 => false], ['missed_dose' => 'Пропущенную таблетку не принимайте.']);
    // Утром в 8:00 и вечером в 20:00: окна по 6 часов.
    $this->prescription = timedPrescription($this->patient, $this->aviandr->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-11',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    app(SyncDoseEvents::class)->handle($this->patient);
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();
});

/**
 * Момент по Москве: «2026-10-19 08:05».
 */
function moscowTime(?CarbonImmutable $time): ?string
{
    return $time?->setTimezone('Europe/Moscow')->format('Y-m-d H:i');
}

test('patient marks a dose taken now, on time or late', function (string $now, string $statusText) {
    $this->travelTo(CarbonImmutable::parse("2026-10-19 {$now}", 'Europe/Moscow'));
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'taken'])
        ->assertRedirect(route('patient.home'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Отметка сохранена.']);

    $morning->refresh();

    expect($morning->status)->toBe(DoseEventStatus::Taken)
        ->and(moscowTime($morning->taken_at))->toBe("2026-10-19 {$now}")
        ->and(moscowTime($morning->marked_at))->toBe("2026-10-19 {$now}");

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('today.groups.0.doses.0.status_text', $statusText));
})->with([
    'an hour early' => ['07:00', 'Принят в 7:00'],
    'an hour late' => ['09:00', 'Принят в 9:00'],
    'later' => ['09:01', 'Принят поздно, в 9:01'],
]);

test('patient marks the time a dose was taken', function (string $time, string $statusText) {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'taken', 'time' => $time])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('patient.home'));

    $morning->refresh();

    expect(moscowTime($morning->taken_at))->toBe("2026-10-19 {$time}")
        ->and(moscowTime($morning->marked_at))->toBe('2026-10-19 12:00');

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.doses.0.status_text', $statusText)
            ->where('today.groups.0.doses.0.taken_time', $time),
        );
})->with([
    ['07:00', 'Принят в 7:00'],
    ['09:00', 'Принят в 9:00'],
    ['10:30', 'Принят поздно, в 10:30'],
]);

test('the time of a dose is not earlier than an hour before it and not in the future', function (string $time, string $error) {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'taken', 'time' => $time])
        ->assertSessionHasErrors(['time' => $error]);

    expect($morning->refresh()->status)->toBe(DoseEventStatus::Scheduled);
})->with([
    ['06:59', 'Укажите время не раньше 7:00: за 60 минут до планового.'],
    ['12:01', 'Это время ещё не наступило.'],
    ['8 утра', 'Укажите время в формате ЧЧ:ММ.'],
]);

test('a time around midnight belongs to the day next to the dose', function () {
    $patient = User::factory()->patient()->create();
    // Приём в 0:30 и в 23:30.
    $prescription = timedPrescription($patient, catalogDrug('Гидроксизин')->strengths()->sole(), ['morning' => '00:30', 'bedtime' => '23:30'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($patient);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 00:40', 'Europe/Moscow'));
    app(SyncDoseEvents::class)->handle($patient);

    $this->actingAs($patient)
        ->put(route('patient.doses.update', doseOn($patient, '2026-10-19', 'bedtime')), ['status' => 'taken', 'time' => '00:10'])
        ->assertSessionHasNoErrors();
    $this->actingAs($patient)
        ->put(route('patient.doses.update', doseOn($patient, '2026-10-20', 'morning')), ['status' => 'taken', 'time' => '23:50'])
        ->assertSessionHasNoErrors();

    // Вчерашний приём в 23:30 приняли после полуночи, сегодняшний в 0:30 — накануне вечером.
    expect(moscowTime(doseOn($patient, '2026-10-19', 'bedtime', $prescription)->taken_at))->toBe('2026-10-20 00:10')
        ->and(moscowTime(doseOn($patient, '2026-10-20', 'morning', $prescription)->taken_at))->toBe('2026-10-19 23:50');

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.doses.0.status_text', 'Принят 19.10 в 23:50')
            // Вчерашний приём в 0:30 без отметки тоже виден.
            ->where('yesterday.groups.0.time', '0:30')
            ->where('yesterday.groups.1.time', '23:30')
            ->where('yesterday.groups.1.doses.0.status_text', 'Принят 20.10 в 0:10'),
        );
});

test('patient marks a dose not taken with a reason', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'skipped', 'reason' => 'forgot', 'time' => '09:00'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Отметка сохранена.']);

    $morning->refresh();

    expect($morning->status)->toBe(DoseEventStatus::Skipped)
        ->and($morning->skip_reason)->toBe(DoseSkipReason::Forgot)
        ->and($morning->skip_comment)->toBeNull()
        ->and($morning->taken_at)->toBeNull()
        ->and(moscowTime($morning->marked_at))->toBe('2026-10-19 12:00');

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.doses.0.status_text', 'Не принят: забыл')
            ->where('today.groups.0.doses.0.skip_reason', 'forgot'),
        );
});

test('a reason is required and «Другое» needs a comment', function (array $data, array $errors) {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'skipped', ...$data])
        ->assertSessionHasErrors($errors);

    expect($morning->refresh()->status)->toBe(DoseEventStatus::Scheduled);
})->with([
    'no reason' => [[], ['reason' => 'Выберите причину.']],
    'unknown reason' => [['reason' => 'bored'], ['reason' => 'Выберите причину из списка.']],
    'other without comment' => [['reason' => 'other', 'comment' => '  '], ['comment' => 'Для причины «Другое» напишите комментарий.']],
    'long comment' => [['reason' => 'unwell', 'comment' => str_repeat('а', 501)], ['comment' => 'Комментарий — не больше 500 символов.']],
    'unknown mark' => [['status' => 'missed'], ['status' => 'Отметка указана неверно.']],
]);

test('«Другое» is saved with the comment', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $morning), ['status' => 'skipped', 'reason' => 'other', 'comment' => 'Был в дороге'])
        ->assertSessionHasNoErrors();

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.doses.0.status_text', 'Не принят: другое')
            ->where('today.groups.0.doses.0.skip_comment', 'Был в дороге'),
        );
});

test('a mark can be changed', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');
    $this->actingAs($this->patient);

    $this->put(route('patient.doses.update', $morning), ['status' => 'skipped', 'reason' => 'other', 'comment' => 'Был в дороге']);
    $this->put(route('patient.doses.update', $morning), ['status' => 'taken', 'time' => '09:30', 'comment' => 'лишнее']);

    $morning->refresh();

    expect($morning->status)->toBe(DoseEventStatus::Taken)
        ->and(moscowTime($morning->taken_at))->toBe('2026-10-19 09:30')
        ->and($morning->skip_reason)->toBeNull()
        ->and($morning->skip_comment)->toBeNull();

    $this->put(route('patient.doses.update', $morning), ['status' => 'skipped', 'reason' => 'ran_out']);

    $morning->refresh();

    expect($morning->status)->toBe(DoseEventStatus::Skipped)
        ->and($morning->skip_reason)->toBe(DoseSkipReason::RanOut)
        ->and($morning->taken_at)->toBeNull();
});

test('a missed dose of yesterday is marked taken after its window closed', function () {
    $evening = doseOn($this->patient, '2026-10-18', 'evening');

    expect($evening->status)->toBe(DoseEventStatus::Missed);

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $evening), ['status' => 'taken', 'time' => '20:15'])
        ->assertSessionHasNoErrors();

    $evening->refresh();

    expect($evening->status)->toBe(DoseEventStatus::Taken)
        ->and(moscowTime($evening->taken_at))->toBe('2026-10-18 20:15')
        ->and($evening->isTakenLate())->toBeFalse()
        ->and($evening->isMarkedAfterWindow())->toBeTrue();

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('yesterday.groups.1.time', '20:00')
            ->where('yesterday.groups.1.doses.0.status_text', 'Принят в 20:15')
            ->where('yesterday.groups.1.doses.0.marked_late', '19.10 в 12:00'),
        );
});

test('doses before yesterday cannot be marked or unmarked', function () {
    $old = DoseEvent::factory()->for($this->prescription)->create([
        'local_date' => '2026-10-17',
        'scheduled_at' => CarbonImmutable::parse('2026-10-17 20:00', 'Europe/Moscow'),
        'window_closes_at' => CarbonImmutable::parse('2026-10-18 02:00', 'Europe/Moscow'),
        'status' => DoseEventStatus::Missed,
    ]);
    $this->actingAs($this->patient);

    foreach ([['status' => 'taken', 'time' => '20:00'], ['status' => 'skipped', 'reason' => 'forgot']] as $data) {
        $this->put(route('patient.doses.update', $old), $data)
            ->assertRedirect(route('patient.home'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Отмечать можно только приёмы за сегодня и вчера.']);
    }

    $old->update(['status' => DoseEventStatus::Taken, 'taken_at' => $old->scheduled_at, 'marked_at' => $old->scheduled_at]);

    $this->delete(route('patient.doses.cancel', $old))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Отмечать можно только приёмы за сегодня и вчера.']);

    expect($old->refresh()->status)->toBe(DoseEventStatus::Taken);
});

test('a dose cannot be marked earlier than an hour before its time', function () {
    $evening = doseOn($this->patient, '2026-10-19', 'evening');
    $this->actingAs($this->patient);

    foreach ([['status' => 'taken'], ['status' => 'skipped', 'reason' => 'forgot']] as $data) {
        $this->put(route('patient.doses.update', $evening), $data)
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Приём можно отметить не раньше чем за 60 минут до планового времени.']);
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:59', 'Europe/Moscow'));
    $this->post(route('patient.doses.take-all'), ['doses' => [$evening->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Приём можно отметить не раньше чем за 60 минут до планового времени.']);

    expect($evening->refresh()->status)->toBe(DoseEventStatus::Scheduled);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 19:00', 'Europe/Moscow'));
    $this->put(route('patient.doses.update', $evening), ['status' => 'taken'])->assertSessionHasNoErrors();

    expect($evening->refresh()->status)->toBe(DoseEventStatus::Taken);
});

test('doses of other patients are not found', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');
    $this->actingAs(User::factory()->patient()->create());

    $this->put(route('patient.doses.update', $morning), ['status' => 'taken'])->assertNotFound();
    $this->delete(route('patient.doses.cancel', $morning))->assertNotFound();
    $this->post(route('patient.doses.take-all'), ['doses' => [$morning->id]])->assertNotFound();
    $this->put('/patient/doses/abc', ['status' => 'taken'])->assertNotFound();

    $this->actingAs($this->doctor)
        ->put(route('patient.doses.update', $morning), ['status' => 'taken'])
        ->assertForbidden();

    expect($morning->refresh()->status)->toBe(DoseEventStatus::Scheduled);
});

test('cancelling a mark returns the dose to scheduled or missed', function () {
    $today = doseOn($this->patient, '2026-10-19', 'morning');
    $yesterday = doseOn($this->patient, '2026-10-18', 'evening');
    $this->actingAs($this->patient);

    $this->put(route('patient.doses.update', $today), ['status' => 'taken']);
    $this->put(route('patient.doses.update', $yesterday), ['status' => 'skipped', 'reason' => 'other', 'comment' => 'Был в дороге']);

    foreach ([$today, $yesterday] as $dose) {
        $this->delete(route('patient.doses.cancel', $dose))
            ->assertRedirect(route('patient.home'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Отметка отменена.']);
    }

    // Окно утреннего приёма открыто до 14:00, вчерашнего вечернего закрылось ночью.
    expect($today->refresh()->only(['status', 'taken_at', 'marked_at', 'skip_reason', 'skip_comment']))->toBe([
        'status' => DoseEventStatus::Scheduled,
        'taken_at' => null,
        'marked_at' => null,
        'skip_reason' => null,
        'skip_comment' => null,
    ])
        ->and($yesterday->refresh()->status)->toBe(DoseEventStatus::Missed)
        ->and($yesterday->skip_comment)->toBeNull();
});

test('a dose marked in advance goes to the changed prescription after the mark is cancelled', function () {
    // 19:30: вечерний приём отмечен заранее, потом врач изменил назначение.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 19:30', 'Europe/Moscow'));
    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', doseOn($this->patient, '2026-10-19', 'evening')), ['status' => 'taken']);
    $this->actingAs($this->doctor)
        ->put(route('doctor.patients.prescriptions.update', [$this->patient, $this->prescription]), prescriptionPayload($this->aviandr, [
            'intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 2]],
            'starts_on' => '2026-10-11',
        ]))
        ->assertSessionHasNoErrors();
    $changed = Prescription::query()->where('previous_id', $this->prescription->id)->sole();

    expect(doseOn($this->patient, '2026-10-19', 'evening')->prescription_id)->toBe($this->prescription->id);

    $this->actingAs($this->patient)
        ->delete(route('patient.doses.cancel', doseOn($this->patient, '2026-10-19', 'evening')))
        ->assertSessionHasNoErrors();

    $evening = doseOn($this->patient, '2026-10-19', 'evening');

    expect($evening->prescription_id)->toBe($changed->id)
        ->and($evening->tablets)->toBe('2.0')
        ->and($evening->status)->toBe(DoseEventStatus::Scheduled);
});

test('«Принял всё» marks the unmarked doses of a group taken', function () {
    $sertraline = timedPrescription($this->patient, catalogDrug('Сертралин', [50 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($this->patient);
    $aviandr = doseOn($this->patient, '2026-10-19', 'morning', $this->prescription);
    $skipped = doseOn($this->patient, '2026-10-19', 'morning', $sertraline);
    $missed = doseOn($this->patient, '2026-10-18', 'morning', $this->prescription);
    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $skipped), ['status' => 'skipped', 'reason' => 'ran_out']);

    $this->post(route('patient.doses.take-all'), ['doses' => [$aviandr->id, $skipped->id, $missed->id]])
        ->assertRedirect(route('patient.home'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Приёмы отмечены.']);

    expect($aviandr->refresh()->status)->toBe(DoseEventStatus::Taken)
        ->and(moscowTime($aviandr->taken_at))->toBe('2026-10-19 12:00')
        ->and($missed->refresh()->status)->toBe(DoseEventStatus::Taken)
        ->and($skipped->refresh()->status)->toBe(DoseEventStatus::Skipped);

    // Вчерашний приём принят сейчас: время отметки совпадает со временем приёма и отдельно не показывается.
    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('yesterday.groups.0.doses.0.status_text', 'Принят поздно, 19.10 в 12:00')
            ->where('yesterday.groups.0.doses.0.marked_late', null),
        );

    $this->post(route('patient.doses.take-all'), ['doses' => [$aviandr->id, $skipped->id]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Эти приёмы уже отмечены.']);
});

test('«Принял всё» marks nothing when one of the doses cannot be marked', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');
    $evening = doseOn($this->patient, '2026-10-19', 'evening');

    $this->actingAs($this->patient)
        ->post(route('patient.doses.take-all'), ['doses' => [$morning->id, $evening->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Приём можно отметить не раньше чем за 60 минут до планового времени.']);

    expect($morning->refresh()->status)->toBe(DoseEventStatus::Scheduled);

    $this->post(route('patient.doses.take-all'), ['doses' => []])->assertSessionHasErrors('doses');
});

test('marked doses stay as they are when the scheduler marks missed ones', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');
    $this->actingAs($this->patient)->put(route('patient.doses.update', $morning), ['status' => 'skipped', 'reason' => 'unwell']);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:00', 'Europe/Moscow'));
    $this->artisan('app:dose-events:mark-missed')->expectsOutputToContain('Пропущенных приёмов: 0.');

    expect($morning->refresh()->status)->toBe(DoseEventStatus::Skipped);
});
