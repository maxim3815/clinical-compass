<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseSkipReason;
use App\Enums\Mood;
use App\Events\PatientSignalsChanged;
use App\Models\MoodMark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Приёмы за вчера и сегодня: утром в 8:00 и вечером в 20:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $aviandr = catalogDrug('Авиандр', [20 => false]);
    timedPrescription($this->patient, $aviandr->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-11',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    app(SyncDoseEvents::class)->handle($this->patient);
});

test('after a mark the patient picks a face for that time and can change it the same day', function () {
    Event::fake([PatientSignalsChanged::class]);
    $this->actingAs($this->patient)->put(route('patient.doses.update', doseOn($this->patient, '2026-10-19', 'morning')), ['status' => 'taken']);

    $this->actingAs($this->patient)
        ->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'neutral'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($this->patient)
        ->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'sad'])
        ->assertSessionHasNoErrors();

    $mark = MoodMark::query()->sole();

    expect($mark->mood)->toBe(Mood::Sad)
        ->and($mark->local_date->toDateString())->toBe('2026-10-19')
        ->and($mark->slot)->toBe('8:00');

    // Лицо у сегодняшней группы; у вечерней, ещё не отмеченной, лица нет.
    $this->actingAs($this->patient)->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.time', '8:00')
            ->where('today.groups.0.mood', 'sad')
            ->where('today.groups.1.mood', null));

    Event::assertDispatched(PatientSignalsChanged::class, fn (PatientSignalsChanged $event): bool => $event->doctor->is($this->doctor));
});

test('a skipped dose opens the faces too', function () {
    $this->actingAs($this->patient)->put(route('patient.doses.update', doseOn($this->patient, '2026-10-19', 'morning')), [
        'status' => 'skipped',
        'reason' => DoseSkipReason::Unwell->value,
    ]);

    $this->actingAs($this->patient)
        ->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'sad'])
        ->assertSessionHasNoErrors();

    expect(MoodMark::query()->sole()->mood)->toBe(Mood::Sad);
});

test('no face without a mark today at that time', function (string $slot, string $mood, string $error) {
    // Вчерашний вечер отмечен, но лица — только у сегодняшних отметок.
    $this->actingAs($this->patient)->put(route('patient.doses.update', doseOn($this->patient, '2026-10-18', 'evening')), ['status' => 'taken']);

    $this->actingAs($this->patient)
        ->post(route('patient.moods.store'), ['slot' => $slot, 'mood' => $mood])
        ->assertSessionHasErrors($error);

    expect(MoodMark::query()->count())->toBe(0);
})->with([
    'morning not marked yet' => ['8:00', 'happy', 'slot'],
    'no dose at this time' => ['9:00', 'happy', 'slot'],
    'yesterday evening' => ['20:00', 'happy', 'slot'],
    'leading zero is not a group time' => ['08:00', 'happy', 'slot'],
    'unknown face' => ['8:00', 'angry', 'mood'],
]);

test('the doctor sees the faces by day and a low mood mark after three sad days, not in the queue', function () {
    $days = ['2026-10-16' => [Mood::Sad], '2026-10-17' => [Mood::Sad, Mood::Sad], '2026-10-18' => [Mood::Sad]];

    foreach ($days as $date => $moods) {
        foreach ($moods as $index => $mood) {
            $this->patient->moodMarks()->create(['local_date' => $date, 'slot' => $index === 0 ? '8:00' : '20:00', 'mood' => $mood]);
        }
    }

    // Сегодня лиц ещё нет: серия кончается вчера и метка стоит.
    $this->actingAs($this->doctor)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.data.0.low_mood', 3)
            ->where('attention', []));

    $this->actingAs($this->doctor)->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('moods.streak', ['days' => 3, 'from' => '16.10', 'to' => '18.10'])
            ->where('moods.any', true)
            ->where('moods.planned', true)
            ->where('moods.slots', ['8:00', '20:00'])
            ->has('moods.days', 14)
            ->where('moods.days.13', ['iso' => '2026-10-19', 'date' => '19.10', 'weekday' => 'пн', 'long' => 'понедельник, 19 октября', 'today' => true, 'in_streak' => false, 'marks' => []])
            ->where('moods.days.10.in_streak', true)
            ->where('moods.days.12.in_streak', true)
            ->where('moods.days.9.in_streak', false)
            // По времени приёма: «8:00» раньше «20:00».
            ->where('moods.days.11.marks', [
                ['slot' => '8:00', 'mood' => 'sad', 'label' => 'Плохое'],
                ['slot' => '20:00', 'mood' => 'sad', 'label' => 'Плохое'],
            ]));

    // Не грустное лицо сегодня прерывает серию.
    $this->patient->moodMarks()->create(['local_date' => '2026-10-19', 'slot' => '8:00', 'mood' => Mood::Neutral]);

    $this->actingAs($this->doctor)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('patients.data.0.low_mood', null));
});

test('tapping the chosen face again clears it', function () {
    $this->actingAs($this->patient)->put(route('patient.doses.update', doseOn($this->patient, '2026-10-19', 'morning')), ['status' => 'taken']);
    $this->actingAs($this->patient)->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'sad']);

    $this->actingAs($this->patient)
        ->delete(route('patient.moods.destroy'), ['slot' => '8:00'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(MoodMark::query()->count())->toBe(0);
});

test('cancelling the last mark of that time takes the face away, another mark keeps it', function () {
    $morning = doseOn($this->patient, '2026-10-19', 'morning');
    $this->actingAs($this->patient)->put(route('patient.doses.update', $morning), ['status' => 'taken']);
    $this->actingAs($this->patient)->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'sad']);
    // Вчерашнее лицо отменой сегодняшней отметки не задевается.
    $this->patient->moodMarks()->create(['local_date' => '2026-10-18', 'slot' => '8:00', 'mood' => Mood::Happy]);

    $this->actingAs($this->patient)->delete(route('patient.doses.cancel', $morning))->assertSessionHasNoErrors();

    expect(MoodMark::query()->pluck('local_date')->map->toDateString()->all())->toBe(['2026-10-18']);
});

test('a patient without planned doses has no faces yet', function () {
    $other = User::factory()->patientOf($this->doctor)->create();

    $this->actingAs($this->doctor)->get(route('doctor.patients.show', $other))
        ->assertInertia(fn (Assert $page) => $page
            ->where('moods.any', false)
            ->where('moods.planned', false)
            ->where('moods.streak', null));
});

test('without a psychiatrist there are no faces: nobody would see them', function () {
    $therapist = User::factory()->therapist()->create();
    $patient = User::factory()->patientOf($therapist)->create();
    timedPrescription($patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-11',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($patient);
    $this->actingAs($patient)->put(route('patient.doses.update', doseOn($patient, '2026-10-19', 'morning')), ['status' => 'taken']);

    $this->actingAs($patient)->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('moodEnabled', false));

    $this->actingAs($patient)
        ->post(route('patient.moods.store'), ['slot' => '8:00', 'mood' => 'sad'])
        ->assertSessionHasErrors('slot');

    expect(MoodMark::query()->count())->toBe(0);
});
