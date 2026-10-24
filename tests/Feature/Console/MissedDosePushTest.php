<?php

use App\Enums\DoseEventStatus;
use App\Models\User;
use App\Notifications\MissedDoses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00', 'UTC'));
    $this->patient = User::factory()->patient()->create();
    $attributes = ['starts_on' => '2026-10-12', 'effective_at' => now()->subWeek()];
    $this->catalog = timedPrescription($this->patient, catalogDrug('Эсциталопрам', [10 => true], [
        'missed_dose' => 'Не принимайте двойную дозу. Следующую примите в обычное время.',
    ])->strengths()->sole(), ['morning' => '08:00'], $attributes);
    // «Другой» препарат пациента: справочника и совета о пропуске у него нет.
    $this->own = ownPrescription($this->patient, null, ['morning' => '08:00'], [...$attributes, 'dose' => '1 капсула']);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    subscribeToPush($this->patient);
});

test('the patient learns what to do when a dose window closes without a mark', function () {
    // Окно приёма раз в сутки закрывается через 12 часов, в 20:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:00', 'UTC'));
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    Notification::assertSentToTimes($this->patient, MissedDoses::class, 1);
    Notification::assertSentTo($this->patient, MissedDoses::class, function (MissedDoses $notification): bool {
        $message = $notification->toWebPush($this->patient)->toArray();

        return $message['title'] === 'Приём в 8:00 не отмечен'
            && $message['body'] === implode("\n", [
                'Если вы уже приняли, отметьте это в приложении.',
                'Фенибут, 1 капсула',
                'Эсциталопрам, 1 таблетка, 10 мг. Если не приняли, не принимайте двойную дозу. Следующую примите в обычное время.',
            ])
            && ! isset($message['actions'])
            && $message['tag'] === 'dose-'.CarbonImmutable::parse('2026-10-19 05:00', 'UTC')->getTimestamp();
    });
});

test('no missed dose push without push or when the window closed long ago', function () {
    $other = User::factory()->patient()->create();
    timedPrescription($other, catalogDrug('Атаракс', [25 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $this->artisan('app:dose-events:sync')->assertSuccessful();

    // Планировщик стоял: окно закрылось в 20:00, а пропуск отмечен только в 20:30.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:30', 'UTC'));
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    expect($this->patient->doseEvents()->where('status', DoseEventStatus::Missed)->count())->toBe(2);
    Notification::assertNothingSent();
});

test('the missed dose push is not sent if the patient marked the dose meanwhile', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:00', 'UTC'));
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    Notification::assertSentTo($this->patient, MissedDoses::class, function (MissedDoses $notification): bool {
        $notification->doses->each->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);

        return ! $notification->shouldSend($this->patient, 'webpush');
    });
});
