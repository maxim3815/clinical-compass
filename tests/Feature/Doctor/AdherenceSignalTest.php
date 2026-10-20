<?php

use App\Actions\CancelDoseMark;
use App\Actions\SkipDose;
use App\Actions\TakeAllDoses;
use App\Actions\TakeDose;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\RussianTimezone;
use App\Events\AdherenceSignalsChanged;
use App\Http\Controllers\Doctor\PatientController;
use App\Models\CareRelationship;
use App\Models\DoseEvent;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Adherence\DoseTally;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве, 20 октября.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    $this->aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
    $this->sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();
});

/**
 * Сигнал пациента, как его видит карточка.
 *
 * @return array{share: int|null, low_share: bool, consecutive_misses: int|null, raised: bool}
 */
function signalOf(User $patient): array
{
    return AdherenceSignals::forPatient($patient->fresh() ?? $patient)->toArray();
}

/**
 * Утренние приёмы курса подряд по датам со статусами по порядку. Последний — вчера, 19 октября:
 * окно уже закрылось, или сегодня, если приём отмечен.
 *
 * @param  list<string>  $statuses
 */
function morningDoses(Prescription $prescription, array $statuses, string $last = '2026-10-19'): void
{
    $date = CarbonImmutable::parse($last, 'UTC')->subDays(count($statuses) - 1);

    foreach ($statuses as $status) {
        adherenceDose($prescription, $date->toDateString(), $status);
        $date = $date->addDay();
    }
}

/**
 * Пациент врача с утренним курсом с 1 октября и приёмами по статусам до сегодня.
 *
 * @param  list<string>  $statuses
 */
function patientWithDoses(User $doctor, DrugStrength $strength, string $name, array $statuses): User
{
    $patient = User::factory()->patientOf($doctor)->create(['name' => $name]);
    morningDoses(adherenceCourse($doctor, $patient, $strength, '2026-10-01', times: ['morning' => '08:00']), $statuses);

    return $patient;
}

/**
 * С 14 по 18 октября приняты оба приёма, 19 октября утром «Не принял», вечером пропущен,
 * сегодня утренний приём запланирован: 10 из 12 за 7 дней и два непринятых подряд.
 */
function twoMissesAfterGoodDays(Prescription $course): void
{
    foreach (range(14, 18) as $day) {
        adherenceDose($course, '2026-10-'.$day);
        adherenceDose($course, '2026-10-'.$day, 'taken', 'evening');
    }

    adherenceDose($course, '2026-10-19', 'skipped');
    adherenceDose($course, '2026-10-19', 'missed', 'evening');
    adherenceDose($course, '2026-10-20', 'scheduled');
}

test('the share counts the last 7 local days including today by the rules of the card', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    // 13 октября уже за пределами 7 дней.
    morningDoses($course, ['missed', 'taken', 'taken', 'skipped', 'taken', 'taken', 'taken', 'taken'], '2026-10-20');
    // Вечерний приём сегодня ещё запланирован, вчерашний с закрытым окном scheduler ещё не отметил: не учитываются.
    adherenceDose($course, '2026-10-20', 'scheduled', 'evening');
    adherenceDose($course, '2026-10-19', 'scheduled', 'evening');

    $tally = DoseTally::of(DoseEvent::query()->whereBetween('local_date', ['2026-10-14', '2026-10-20'])->get());

    expect(signalOf($this->patient))->toBe(['share' => 85, 'low_share' => false, 'consecutive_misses' => null, 'raised' => false])
        ->and($tally->share())->toBe(85);
});

test('the share follows the local date of a patient far from Moscow', function () {
    // 1:00 21 октября на Камчатке, ещё 20 октября в Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 13:00', 'UTC'));
    $this->patient->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    $course = adherenceCourse($this->doctor, $this->patient->fresh() ?? $this->patient, $this->aviandr, '2026-10-01');
    morningDoses($course, ['missed', 'taken', 'taken', 'taken', 'taken', 'taken', 'taken'], '2026-10-20');

    // По Москве в 7 дней попал бы пропуск 14 октября.
    expect(signalOf($this->patient)['share'])->toBe(100);
});

test('the share is empty without counted doses', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-20');
    adherenceDose($course, '2026-10-20', 'scheduled');

    expect(signalOf($this->patient))->toBe(['share' => null, 'low_share' => false, 'consecutive_misses' => null, 'raised' => false])
        ->and(signalOf(User::factory()->patientOf($this->doctor)->create())['share'])->toBeNull();
});

test('medications added by the patient and as needed doses do not count', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    morningDoses($course, ['taken', 'taken']);
    $own = ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-01']);
    morningDoses($own, ['missed', 'missed', 'missed']);
    $asNeeded = Prescription::factory()->for($this->patient, 'patient')->for($this->sertraline, 'strength')->asNeeded('1', 1)
        ->create(['prescriber_id' => $this->doctor->id, 'starts_on' => '2026-10-01']);
    asNeededDosesOn($asNeeded, '2026-10-19', 3);

    expect(signalOf($this->patient))->toBe(['share' => 100, 'low_share' => false, 'consecutive_misses' => null, 'raised' => false]);
});

test('the signal is raised when the share is below the threshold from the config', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    // 5 из 7: 71%, последний приём принят.
    morningDoses($course, ['taken', 'missed', 'taken', 'skipped', 'taken', 'taken', 'taken'], '2026-10-20');

    expect(signalOf($this->patient))->toBe(['share' => 71, 'low_share' => true, 'consecutive_misses' => null, 'raised' => true]);

    config(['medications.adherence.signal_share' => 70]);

    expect(signalOf($this->patient)['raised'])->toBeFalse();
});

test('the share is rounded down at the threshold and counts every course', function () {
    // 6 из 7 и 5 из 7: вместе 11 из 14, 78,6% — это 78%, а не 79%.
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), ['taken', 'taken', 'missed', 'taken', 'taken', 'taken', 'taken'], '2026-10-20');
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-01'), ['missed', 'missed', 'taken', 'taken', 'taken', 'taken', 'taken'], '2026-10-20');
    config(['medications.adherence.signal_share' => 79]);

    expect(signalOf($this->patient))->toMatchArray(['share' => 78, 'low_share' => true]);
});

test('the signal is raised by doses not taken in a row at the end of a course', function () {
    twoMissesAfterGoodDays(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'));

    // Доля не ниже порога, но два последних учтённых приёма не приняты.
    expect(signalOf($this->patient))->toBe(['share' => 83, 'low_share' => false, 'consecutive_misses' => 2, 'raised' => true]);

    config(['medications.adherence.signal_consecutive' => 3]);

    expect(signalOf($this->patient))->toMatchArray(['consecutive_misses' => null, 'raised' => false]);
});

test('doses in a row follow the planned time, not the order they were saved in', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    // Последний по плановому времени приём принят, хотя сохранён первым.
    adherenceDose($course, '2026-10-19');
    adherenceDose($course, '2026-10-17', 'missed');
    adherenceDose($course, '2026-10-18', 'skipped');

    expect(signalOf($this->patient)['consecutive_misses'])->toBeNull();
});

test('doses not taken in a row continue through a changed prescription', function () {
    $first = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01', closedAt($this->doctor, '2026-10-19 05:00', PrescriptionCloseReason::Changed));
    $second = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-19', ['previous_id' => $first->id]);
    morningDoses($first, ['taken', 'taken', 'taken', 'missed'], '2026-10-18');
    adherenceDose($first, '2026-10-18', 'missed', 'evening');
    morningDoses($second, ['missed', 'skipped'], '2026-10-20');

    expect(signalOf($this->patient)['consecutive_misses'])->toBe(4);
});

test('doses not taken in different courses are not a row', function () {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    $sertraline = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-01');
    morningDoses($aviandr, ['taken', 'taken', 'taken', 'taken', 'taken', 'missed']);
    morningDoses($sertraline, ['taken', 'taken', 'taken', 'taken', 'taken', 'missed']);

    expect(signalOf($this->patient))->toMatchArray(['consecutive_misses' => null, 'raised' => false]);
});

test('a finished course does not raise the signal by doses not taken in a row', function () {
    // Врач отменил курс сегодня утром, после двух непринятых приёмов.
    twoMissesAfterGoodDays(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01', closedAt($this->doctor, '2026-10-20 04:00')));
    DoseEvent::query()->where('status', DoseEventStatus::Scheduled)->delete();

    expect(signalOf($this->patient))->toMatchArray(['share' => 83, 'consecutive_misses' => null, 'raised' => false]);
});

test('patient rows show the share and the signal', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    morningDoses($course, ['taken', 'taken', 'missed', 'skipped']);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.data.0.adherence', ['share' => 50, 'low_share' => true, 'consecutive_misses' => 2, 'raised' => true])
            ->where('sort', null),
        );
});

test('patients are sorted by the share in both directions with empty shares last', function () {
    $this->patient->update(['name' => 'Борисов Борис Борисович']);
    patientWithDoses($this->doctor, $this->aviandr, 'Антонов Антон Антонович', ['taken', 'missed']);
    patientWithDoses($this->doctor, $this->aviandr, 'Васильев Василий Васильевич', ['taken', 'taken']);
    patientWithDoses($this->doctor, $this->aviandr, 'Григорьев Григорий Григорьевич', ['taken', 'taken', 'taken', 'missed']);

    $names = fn (?string $sort): array => $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index', ['sort' => $sort]))
        ->viewData('page')['props']['patients']['data'];

    expect(array_column($names(null), 'name'))->toBe(['Антонов Антон Антонович', 'Борисов Борис Борисович', 'Васильев Василий Васильевич', 'Григорьев Григорий Григорьевич'])
        ->and(array_column(array_column($names('adherence'), 'adherence'), 'share'))->toBe([50, 75, 100, null])
        ->and(array_column($names('-adherence'), 'name'))->toBe(['Васильев Василий Васильевич', 'Григорьев Григорий Григорьевич', 'Антонов Антон Антонович', 'Борисов Борис Борисович'])
        ->and(array_column($names('name'), 'name'))->toBe(array_column($names(null), 'name'));

    $this->get(route('doctor.patients.index', ['sort' => 'unknown']))
        ->assertInertia(fn (Assert $page) => $page->where('sort', null));
});

test('sorting by the share works with pagination and the search', function () {
    $this->patient->update(['name' => 'Пациент 00']);
    adherenceDose(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), '2026-10-20');

    foreach (range(1, PatientController::PER_PAGE) as $number) {
        patientWithDoses($this->doctor, $this->aviandr, sprintf('Пациент %02d', $number), ['missed']);
    }

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index', ['search' => 'пациент', 'sort' => 'adherence']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 20)
            ->where('patients.data.0.name', 'Пациент 01')
            ->where('patients.data.0.adherence.share', 0)
            ->where('sort', 'adherence')
            ->where('patients.next_page_url', route('doctor.patients.index', ['search' => 'пациент', 'sort' => 'adherence', 'page' => 2])),
        );

    $this->get(route('doctor.patients.index', ['search' => 'пациент', 'sort' => 'adherence', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 1)
            ->where('patients.data.0.name', 'Пациент 00')
            ->where('patients.data.0.adherence.share', 100),
        );

    $this->get(route('doctor.patients.index', ['sort' => '-adherence']))
        ->assertInertia(fn (Assert $page) => $page->where('patients.data.0.name', 'Пациент 00'));
});

test('the number of list queries does not grow with patients', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->doctor)->get(route('doctor.patients.index', ['sort' => 'adherence']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    patientWithDoses($this->doctor, $this->aviandr, 'Антонов Антон Антонович', ['missed', 'missed']);
    $few = $queries();

    foreach (range(1, 5) as $number) {
        $patient = patientWithDoses($this->doctor, $this->aviandr, 'Пациент '.$number, ['taken', 'skipped', 'missed']);
        $patient->patientProfile?->update(['timezone' => RussianTimezone::Samara]);
        morningDoses(adherenceCourse($this->doctor, $patient, $this->sertraline, '2026-10-01', times: ['morning' => '08:00']), ['missed', 'taken']);
    }

    expect($queries())->toBe($few);
});

test('the card shows the signal', function () {
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), ['taken', 'missed', 'missed']);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('adherenceSignal', ['share' => 33, 'low_share' => true, 'consecutive_misses' => 2, 'raised' => true]),
        );
});

test('the counter counts patients with a signal and a dose not taken that the doctor has not seen', function () {
    // Сигнал и непросмотренный пропуск.
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), ['taken', 'missed', 'missed']);
    // Пропуск без сигнала: 6 из 7 и один пропуск в конце.
    patientWithDoses($this->doctor, $this->aviandr, 'Антонов Антон Антонович', ['taken', 'taken', 'taken', 'taken', 'taken', 'taken', 'missed']);
    // Сигнал, но связь завершена.
    $former = patientWithDoses($this->doctor, $this->aviandr, 'Борисов Борис Борисович', ['missed', 'missed']);
    CareRelationship::query()->where('patient_id', $former->id)->update(['ended_at' => now()]);
    // Сигнал у пациента другого врача.
    patientWithDoses(User::factory()->doctor()->create(), $this->aviandr, 'Васильев Василий Васильевич', ['missed', 'missed']);
    // Сигнал только по своему препарату пациента.
    $own = User::factory()->patientOf($this->doctor)->create();
    morningDoses(ownPrescription($own, attributes: ['starts_on' => '2026-10-01']), ['missed', 'missed']);

    expect(AdherenceSignals::count($this->doctor))->toBe(1);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('adherenceSignals', 1));

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('adherenceSignals', 0));
});

test('opening the card hides the patient from the counter until the next dose is not taken', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    morningDoses($course, ['taken', 'missed', 'missed']);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('adherenceSignals', 0));

    expect(careBetween($this->doctor, $this->patient)->adherence_seen_at?->eq(now()))->toBeTrue();

    // Вечерний приём пропущен: окно закрылось позже, чем врач открывал карточку. Подсветка не пропадала.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 23:30', 'UTC'));
    $evening = adherenceDose($course, '2026-10-20', 'missed', 'evening');

    expect(AdherenceSignals::count($this->doctor))->toBe(1);

    $this->get(route('doctor.patients.show', $this->patient));

    expect(AdherenceSignals::count($this->doctor))->toBe(0);

    // Пациент исправил пропуск на «Не принял» после просмотра: окно закрылось раньше, но отметка новая.
    $this->travelTo(now()->addMinutes(5));
    $evening->update(['status' => DoseEventStatus::Skipped, 'marked_at' => now(), 'skip_reason' => DoseSkipReason::Forgot]);

    expect(AdherenceSignals::count($this->doctor))->toBe(1);
});

test('the counter goes to the doctor channel and is counted when broadcast', function () {
    $event = new AdherenceSignalsChanged($this->doctor);
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), ['missed', 'missed']);

    expect($event->broadcastOn()->name)->toBe('private-App.Models.User.'.$this->doctor->id)
        ->and($event->broadcastAs())->toBe('adherence.changed')
        ->and($event->broadcastWith())->toBe(['count' => 1])
        ->and(is_subclass_of($event, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of($event, ShouldBroadcastNow::class))->toBeFalse();
});

test('patient marks notify the doctor after commit', function (Closure $mark) {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    $dose = adherenceDose($course, '2026-10-20', 'scheduled');
    Queue::fake();

    $mark($this->patient, $dose);

    Queue::assertPushed(BroadcastEvent::class, 1);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof AdherenceSignalsChanged
        && $job->event->doctor->is($this->doctor));
})->with([
    'taken' => fn (User $patient, DoseEvent $dose) => app(TakeDose::class)->handle($patient, $dose),
    'not taken' => fn (User $patient, DoseEvent $dose) => app(SkipDose::class)->handle($patient, $dose, DoseSkipReason::Forgot),
    'all taken' => fn (User $patient, DoseEvent $dose) => app(TakeAllDoses::class)->handle($patient, [$dose]),
    'mark cancelled' => function (User $patient, DoseEvent $dose) {
        $dose->update(['status' => DoseEventStatus::Taken, 'taken_at' => now(), 'marked_at' => now()]);
        app(CancelDoseMark::class)->handle($patient, $dose);
    },
]);

test('marks of a patient without a doctor and failed marks notify nobody', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    $dose = adherenceDose($course, '2026-10-20', 'scheduled');
    Queue::fake();

    try {
        DB::transaction(function () use ($dose) {
            app(SkipDose::class)->handle($this->patient, $dose, DoseSkipReason::Forgot);

            throw new RuntimeException('Откат');
        });
    } catch (RuntimeException) {
    }

    CareRelationship::query()->update(['ended_at' => now()]);
    app(TakeDose::class)->handle($this->patient, $dose);

    Queue::assertNothingPushed();
});

test('opening the card notifies the doctor', function () {
    Queue::fake();

    $this->actingAs($this->doctor)->get(route('doctor.patients.show', $this->patient))->assertOk();

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof AdherenceSignalsChanged
        && $job->event->doctor->is($this->doctor));
});

test('marking missed doses notifies each doctor once', function () {
    // Два пациента врача пропустили по два приёма.
    morningDoses(adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01'), ['scheduled', 'scheduled']);
    $second = User::factory()->patientOf($this->doctor)->create();
    morningDoses(adherenceCourse($this->doctor, $second, $this->aviandr, '2026-10-01'), ['scheduled']);
    // У пациента другого врача пропущен только свой препарат, у пациента третьего врача окно ещё открыто.
    $other = User::factory()->patientOf(User::factory()->doctor()->create())->create();
    morningDoses(ownPrescription($other, attributes: ['starts_on' => '2026-10-01']), ['scheduled']);
    $thirdDoctor = User::factory()->doctor()->create();
    $third = User::factory()->patientOf($thirdDoctor)->create();
    adherenceDose(adherenceCourse($thirdDoctor, $third, $this->aviandr, '2026-10-01'), '2026-10-20', 'scheduled');
    Queue::fake();

    $this->artisan('app:dose-events:mark-missed')
        ->expectsOutputToContain('Пропущенных приёмов: 4.')
        ->assertSuccessful();

    Queue::assertPushed(BroadcastEvent::class, 1);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof AdherenceSignalsChanged
        && $job->event->doctor->is($this->doctor));
    expect(DoseEvent::query()->where('status', DoseEventStatus::Missed)->count())->toBe(4);

    $this->artisan('app:dose-events:mark-missed')->expectsOutputToContain('Пропущенных приёмов: 0.');

    Queue::assertPushed(BroadcastEvent::class, 1);
});
