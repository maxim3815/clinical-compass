<?php

use App\Enums\DoseEventStatus;
use App\Enums\RussianTimezone;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    // 8:00 по Москве: время утреннего приёма.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 05:00', 'UTC'));
    $this->patient = User::factory()->patient()->create();
    $attributes = ['starts_on' => '2026-10-12', 'effective_at' => now()->subWeek()];
    timedPrescription($this->patient, catalogDrug('Эсциталопрам', [10 => true])->strengths()->sole(), ['morning' => '08:00'], $attributes);
    timedPrescription($this->patient, catalogDrug('Атаракс', [25 => true])->strengths()->sole(), ['morning' => '08:00'], $attributes);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $this->doses = $this->patient->doseEvents()->whereDate('local_date', '2026-10-19')->orderBy('id')->get();
});

/**
 * Ссылка кнопки из напоминания: как в App\Notifications\DoseReminder.
 *
 * @param  iterable<DoseEvent>  $doses
 */
function pushActionUrl(string $action, iterable $doses, ?CarbonImmutable $expires = null): string
{
    $doses = collect($doses);

    return URL::temporarySignedRoute('push.doses.'.$action, $expires ?? $doses->min('window_closes_at'), [
        'doses' => $doses->pluck('id')->implode(','),
    ], absolute: false);
}

test('take marks every dose of the reminder as taken now', function () {
    $this->postJson(pushActionUrl('take', $this->doses))
        ->assertOk()
        ->assertExactJson(['message' => 'Приёмы отмечены']);

    $this->doses->each(function (DoseEvent $dose): void {
        $dose->refresh();

        expect($dose->status)->toBe(DoseEventStatus::Taken)
            ->and($dose->taken_at?->eq(now()))->toBeTrue()
            ->and($dose->marked_at?->eq(now()))->toBeTrue();
    });

    // Повторное нажатие на старый пуш ничего не меняет.
    $this->postJson(pushActionUrl('take', $this->doses))->assertExactJson(['message' => 'Приём уже отмечен']);
});

test('take of a single dose says it in the singular', function () {
    $this->postJson(pushActionUrl('take', [$this->doses->first()]))->assertExactJson(['message' => 'Приём отмечен']);

    expect($this->doses->last()->refresh()->status)->toBe(DoseEventStatus::Scheduled);
});

test('take refuses doses that cannot be marked yet', function () {
    $tomorrow = $this->patient->doseEvents()->whereDate('local_date', '2026-10-20')->get();

    $this->postJson(pushActionUrl('take', $tomorrow))
        ->assertConflict()
        ->assertJsonPath('message', fn (string $message) => $message !== '');

    expect($tomorrow->fresh()->pluck('status')->unique()->all())->toBe([DoseEventStatus::Scheduled]);
});

test('snooze holds the reminder for fifteen minutes and says when it comes in local time', function () {
    $this->patient->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    $this->doses->first()->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);

    // 17:20 на Камчатке.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 05:05', 'UTC'));
    $this->postJson(pushActionUrl('snooze', $this->doses))
        ->assertOk()
        ->assertExactJson(['message' => 'Напомним в 17:20']);

    expect($this->doses->first()->refresh()->snoozed_until)->toBeNull()
        ->and($this->doses->last()->refresh()->snoozed_until?->eq(now()->addMinutes(15)))->toBeTrue();

    $this->doses->last()->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);
    $this->postJson(pushActionUrl('snooze', $this->doses))->assertExactJson(['message' => 'Приём уже отмечен']);
});

test('marking a dose in the app drops its snooze', function () {
    $this->postJson(pushActionUrl('snooze', $this->doses))->assertOk();

    $this->actingAs($this->patient)
        ->put(route('patient.doses.update', $this->doses->first()), ['status' => 'taken'])
        ->assertRedirect(route('patient.home'));

    expect($this->doses->first()->refresh()->snoozed_until)->toBeNull()
        ->and($this->doses->last()->refresh()->snoozed_until)->not->toBeNull();
});

test('buttons need a valid signature that ends with the window of the dose', function () {
    $url = pushActionUrl('take', $this->doses);

    // Список приёмов в ссылке подменить нельзя.
    $this->postJson(str_replace('doses='.urlencode($this->doses->pluck('id')->implode(',')), 'doses='.$this->doses->first()->id, $url))
        ->assertForbidden();
    $this->postJson(route('push.doses.take', ['doses' => $this->doses->first()->id], absolute: false))->assertForbidden();

    $this->travelTo($this->doses->min('window_closes_at')->addMinute());
    $this->postJson($url)->assertForbidden();

    expect($this->doses->fresh()->pluck('status')->unique()->all())->toBe([DoseEventStatus::Scheduled]);
});

test('a dose removed by recalculation is not found', function () {
    $url = pushActionUrl('take', [$this->doses->first()]);
    $this->doses->first()->delete();

    $this->postJson($url)->assertNotFound();
});
