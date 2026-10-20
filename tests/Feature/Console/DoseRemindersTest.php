<?php

use App\Actions\SnoozeDoses;
use App\Actions\TakeDose;
use App\Models\DoseEvent;
use App\Models\User;
use App\Notifications\DoseReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // 7:00 по Москве: приём в 8:00 ещё впереди.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00', 'UTC'));
    $this->strength = catalogDrug('Эсциталопрам', [10 => true])->strengths()->sole();
    $this->patient = User::factory()->patient()->create();
    $this->prescription = timedPrescription($this->patient, $this->strength, ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $this->dose = doseOn($this->patient, '2026-10-19', 'morning');
    subscribeToPush($this->patient);
});

/**
 * Запуск планировщика в момент по UTC.
 */
function remindAt(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-19 '.$moment, 'UTC'));
    test()->artisan('app:dose-events:send-reminders')->assertSuccessful();
}

test('the patient is reminded at the time of the dose and twice more while it is on time', function () {
    remindAt('04:59');
    Notification::assertNothingSent();

    remindAt('05:00');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);
    Notification::assertSentTo($this->patient, DoseReminder::class, fn (DoseReminder $reminder) => $reminder->doses->modelKeys() === [$this->dose->id]);

    // Тот же момент второй раз не напоминает.
    remindAt('05:01');
    remindAt('05:29');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);

    remindAt('05:30');
    remindAt('06:00');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 3);

    // Четвёртого напоминания нет, хотя окно приёма открыто до вечера.
    remindAt('06:30');
    remindAt('07:00');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 3);

    expect($this->dose->refresh()->reminded_at?->format('H:i'))->toBe('06:00');
});

test('reminders whose moment passed long ago are not sent', function () {
    // Планировщик стоял с 8:00 до 8:15 по Москве: первое напоминание опоздало.
    remindAt('05:15');
    Notification::assertNothingSent();

    remindAt('05:30');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);
});

test('doses at the same time come in one reminder', function () {
    $other = timedPrescription($this->patient, catalogDrug('Атаракс', [25 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $later = timedPrescription($this->patient, catalogDrug('Сертралин', [50 => true])->strengths()->sole(), ['morning' => '08:10'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $this->artisan('app:dose-events:sync')->assertSuccessful();

    remindAt('05:00');

    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);
    Notification::assertSentTo($this->patient, DoseReminder::class, fn (DoseReminder $reminder) => $reminder->doses->modelKeys() === [
        $this->dose->id,
        doseOn($this->patient, '2026-10-19', 'morning', $other)->id,
    ]);

    remindAt('05:10');

    Notification::assertSentToTimes($this->patient, DoseReminder::class, 2);
    Notification::assertSentTo($this->patient, DoseReminder::class, fn (DoseReminder $reminder) => $reminder->doses->modelKeys() === [
        doseOn($this->patient, '2026-10-19', 'morning', $later)->id,
    ]);
});

test('patients without push are not reminded until they turn it on', function () {
    $this->patient->pushSubscriptions()->delete();

    remindAt('05:00');
    Notification::assertNothingSent();
    expect($this->dose->refresh()->reminded_at)->toBeNull();

    // Пациент включил пуши через пять минут: напоминание о приёме ещё придёт.
    subscribeToPush($this->patient);
    remindAt('05:05');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);
});

test('marked doses are not reminded', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:30', 'UTC'));
    app(TakeDose::class)->handle($this->patient, $this->dose);

    remindAt('05:00');
    remindAt('05:30');

    Notification::assertNothingSent();
});

test('a snoozed reminder comes when the snooze ends and holds back the regular repeat', function () {
    remindAt('05:00');

    // «Через 15 минут» в 8:25: повтор в 8:30 ждёт, напоминание приходит в 8:40.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 05:25', 'UTC'));
    app(SnoozeDoses::class)->handle($this->patient, [$this->dose]);

    remindAt('05:30');
    remindAt('05:39');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 1);

    remindAt('05:40');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 2);
    expect($this->dose->refresh()->snoozed_until)->toBeNull();

    // Обычный повтор в 9:00 остаётся.
    remindAt('06:00');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 3);
});

test('a snoozed reminder comes after the on time interval but not after the window closes', function () {
    remindAt('05:00');
    remindAt('05:30');
    remindAt('06:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'UTC'));
    app(SnoozeDoses::class)->handle($this->patient, [$this->dose]);
    remindAt('07:15');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 4);

    // Окно приёма раз в сутки — 12 часов, до 20:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 16:50', 'UTC'));
    app(SnoozeDoses::class)->handle($this->patient, [$this->dose]);
    remindAt('17:05');
    Notification::assertSentToTimes($this->patient, DoseReminder::class, 4);
});

test('the reminder shows the time, drugs and doses and carries signed buttons', function () {
    $other = timedPrescription($this->patient, catalogDrug('Атаракс', [25 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
        'instruction' => 'Может вызывать сонливость.',
    ]);
    $this->prescription->update(['instruction' => 'За 30 минут до еды.']);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $doses = DoseEvent::query()->whereIn('prescription_id', [$this->prescription->id, $other->id])->whereDate('local_date', '2026-10-19')->orderBy('id')->get();
    $doses->load(['patient.patientProfile', 'prescription.drug', 'prescription.strength']);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 05:00', 'UTC'));
    $message = (new DoseReminder($doses))->toWebPush($this->patient)->toArray();
    // Приёмы по названию препарата, как на экране «Сегодня».
    $ids = doseOn($this->patient, '2026-10-19', 'morning', $other)->id.','.$this->dose->id;

    expect($message['title'])->toBe('Приём в 8:00')
        ->and($message['body'])->toBe("Атаракс, 1 таблетка, 25 мг. Может вызывать сонливость.\nЭсциталопрам, 1 таблетка, 10 мг. За 30 минут до еды.")
        ->and($message['tag'])->toBe('dose-'.CarbonImmutable::parse('2026-10-19 05:00', 'UTC')->getTimestamp())
        ->and(array_column($message['actions'], 'title'))->toBe(['Принял всё', 'Через 15 минут'])
        ->and($message['data']['url'])->toBe('/patient?doses='.urlencode($ids))
        ->and($message['data']['take'])->toStartWith('/push/doses/take?');

    // Кнопка работает без сессии: доступ даёт подпись ссылки.
    $this->postJson($message['data']['take'])
        ->assertOk()
        ->assertJson(['message' => 'Приёмы отмечены']);

    expect($doses->fresh()->pluck('status.value')->all())->toBe(['taken', 'taken']);
});
