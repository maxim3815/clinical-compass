<?php

use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\RussianTimezone;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Adherence\PatientAdherence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве, 20 октября.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
    $this->sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();
});

/**
 * Блок «Приём препаратов» карточки пациента на его сегодняшнюю дату.
 *
 * @return array<string, mixed>
 */
function adherenceOf(User $patient): array
{
    $patient = $patient->fresh() ?? $patient;

    return PatientAdherence::present($patient, $patient->localToday());
}

test('a changed prescription continues the course, a new prescription after cancellation starts another', function () {
    $first = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01', closedAt($this->doctor, '2026-10-10 07:00', PrescriptionCloseReason::Changed));
    $second = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-10', ['previous_id' => $first->id]);
    $cancelled = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-09-01', closedAt($this->doctor, '2026-09-20 09:00'));
    $renewed = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-05');
    adherenceDose($first, '2026-10-09');
    adherenceDose($second, '2026-10-10', 'missed');

    $adherence = adherenceOf($this->patient);

    expect($adherence['courses'])->toHaveCount(2)
        ->and($adherence['courses'][0])->toMatchArray([
            'id' => $first->id,
            'drug' => 'Авиандр',
            'kind' => 'regular',
            'source' => 'doctor',
            'finished' => false,
            'started' => '1 октября',
            'ended' => null,
            'day' => 20,
        ])
        ->and(array_column($adherence['courses'][0]['periods'], 'days'))->toBe(['Дни 15–28', 'Дни 8–14', 'Дни 1–7'])
        ->and($adherence['courses'][0]['periods'][1])->toMatchArray(['taken' => 1, 'counted' => 2, 'share' => 50])
        ->and($adherence['courses'][1])->toMatchArray(['id' => $renewed->id, 'drug' => 'Сертралин', 'started' => '5 октября', 'day' => 16])
        ->and($adherence['finished'])->toHaveCount(1)
        ->and($adherence['finished'][0])->toMatchArray([
            'id' => $cancelled->id,
            'finished' => true,
            'started' => '1 сентября',
            'ended' => '20 сентября',
            'day' => null,
        ])
        ->and(array_column($adherence['finished'][0]['periods'], 'days'))->toBe(['Дни 15–20', 'Дни 8–14', 'Дни 1–7'])
        ->and(array_column($adherence['finished'][0]['periods'], 'current'))->toBe([false, false, false]);
});

test('control periods follow the course day', function (int $day, array $periods) {
    $startsOn = CarbonImmutable::parse('2026-10-20', 'UTC')->subDays($day - 1)->toDateString();
    adherenceCourse($this->doctor, $this->patient, $this->aviandr, $startsOn);

    $course = adherenceOf($this->patient)['courses'][0];

    expect($course['day'])->toBe($day)
        ->and(array_column($course['periods'], 'days'))->toBe($periods)
        ->and(array_column($course['periods'], 'current'))->toBe([true, ...array_fill(0, count($periods) - 1, false)]);
})->with([
    'day 1' => [1, ['Дни 1–7']],
    'day 7' => [7, ['Дни 1–7']],
    'day 8' => [8, ['Дни 8–14', 'Дни 1–7']],
    'day 14' => [14, ['Дни 8–14', 'Дни 1–7']],
    'day 15' => [15, ['Дни 15–28', 'Дни 8–14', 'Дни 1–7']],
    'day 28' => [28, ['Дни 15–28', 'Дни 8–14', 'Дни 1–7']],
    'day 29' => [29, ['Дни 29–56', 'Дни 15–28', 'Дни 8–14', 'Дни 1–7']],
    'day 56' => [56, ['Дни 29–56', 'Дни 15–28', 'Дни 8–14', 'Дни 1–7']],
    'day 57' => [57, ['Дни 57–84', 'Дни 29–56', 'Дни 15–28', 'Дни 8–14', 'Дни 1–7']],
]);

test('period dates are cut by the end of the course', function () {
    // Завершён по дате окончания, второй отменён на 8-й день: 19.09 22:30 UTC — уже 20.09 по Москве.
    adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-09-01', ['ends_on' => '2026-09-16']);
    adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-09-13', closedAt($this->doctor, '2026-09-19 22:30'));
    // Действующий с датой окончания в текущем периоде.
    adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-12', ['ends_on' => '2026-10-23']);

    $adherence = adherenceOf($this->patient);

    expect($adherence['finished'][0])->toMatchArray(['drug' => 'Сертралин', 'started' => '13 сентября', 'ended' => '20 сентября'])
        ->and(array_map(fn (array $period): array => [$period['days'], $period['dates']], $adherence['finished'][0]['periods']))->toBe([
            ['День 8', '20 сентября'],
            ['Дни 1–7', '13–19 сентября'],
        ])
        ->and($adherence['finished'][1])->toMatchArray(['drug' => 'Авиандр', 'started' => '1 сентября', 'ended' => '16 сентября'])
        ->and(array_column($adherence['finished'][1]['periods'], 'dates'))->toBe(['15–16 сентября', '8–14 сентября', '1–7 сентября'])
        ->and(array_map(fn (array $period): array => [$period['days'], $period['dates'], $period['current']], $adherence['courses'][0]['periods']))->toBe([
            ['Дни 8–12', '19–23 октября', true],
            ['Дни 1–7', '12–18 октября', false],
        ]);
});

test('a period counts taken, not taken and missed doses', function () {
    // День 7 курса: период «Дни 1–7» — с 14 по 20 октября.
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');
    adherenceDose($course, '2026-10-14');
    adherenceDose($course, '2026-10-14', 'taken', 'evening', ['taken_at' => '2026-10-14 18:30']);
    adherenceDose($course, '2026-10-15', 'skipped');
    adherenceDose($course, '2026-10-15', 'skipped', 'evening', ['skip_reason' => DoseSkipReason::Unwell, 'skip_comment' => 'Тошнило']);
    adherenceDose($course, '2026-10-16', 'missed');
    // Отмечен на следующий день после закрытия окна в 2:00.
    adherenceDose($course, '2026-10-16', 'taken', 'evening', ['marked_at' => '2026-10-17 06:05']);
    // 17 октября приёмов нет. 18-го утром принят заранее, вечерний ещё не отметил scheduler.
    adherenceDose($course, '2026-10-18', 'taken', 'morning', ['taken_at' => '2026-10-18 04:30']);
    adherenceDose($course, '2026-10-18', 'scheduled', 'evening');
    adherenceDose($course, '2026-10-19', 'skipped', 'morning', ['skip_reason' => DoseSkipReason::RanOut, 'marked_at' => '2026-10-19 12:00']);
    adherenceDose($course, '2026-10-19', 'taken', 'evening');
    adherenceDose($course, '2026-10-20');
    adherenceDose($course, '2026-10-20', 'scheduled', 'evening');

    $result = adherenceOf($this->patient)['courses'][0];

    expect($result['periods'])->toHaveCount(1)
        ->and($result['periods'][0])->toBe([
            'days' => 'Дни 1–7',
            'dates' => '14–20 октября',
            'current' => true,
            'share' => 60,
            'taken' => 6,
            'counted' => 10,
            'on_time' => 5,
            'late' => 1,
            'skipped' => [
                ['label' => 'Забыл', 'count' => 1, 'unwell' => false],
                ['label' => 'Плохо себя чувствовал или были побочные эффекты', 'count' => 1, 'unwell' => true],
                ['label' => 'Закончился препарат', 'count' => 1, 'unwell' => false],
            ],
            'missed' => 1,
            'marked_late' => 2,
            'no_data_days' => 1,
            'by_time_of_day' => [
                ['label' => 'утром', 'share' => 50],
                ['label' => 'вечером', 'share' => 75],
            ],
        ])
        ->and(array_column($result['days'], 'result', 'date'))->toBe([
            '2026-10-14' => 'late',
            '2026-10-15' => 'none',
            '2026-10-16' => 'partial',
            '2026-10-17' => 'no_data',
            '2026-10-18' => 'on_time',
            '2026-10-19' => 'partial',
            '2026-10-20' => 'pending',
        ]);
});

test('a day of the strip lists its doses', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');
    adherenceDose($course, '2026-10-15', 'taken', 'morning', ['taken_at' => '2026-10-15 06:30']);
    adherenceDose($course, '2026-10-15', 'skipped', 'evening', ['skip_reason' => DoseSkipReason::Unwell, 'skip_comment' => 'Тошнило']);
    adherenceDose($course, '2026-10-16', 'taken', 'evening', ['marked_at' => '2026-10-17 06:05']);
    // «Принял» уже после закрытия окна: время отметки совпадает со временем приёма.
    adherenceDose($course, '2026-10-17', 'taken', 'morning', ['taken_at' => '2026-10-17 12:00']);
    adherenceDose($course, '2026-10-17', 'missed', 'evening');
    adherenceDose($course, '2026-10-20', 'scheduled', 'evening');

    $days = collect(adherenceOf($this->patient)['courses'][0]['days'])->keyBy('date');

    expect($days)->toHaveCount(7)
        ->and($days['2026-10-15'])->toMatchArray([
            'label' => '15 октября',
            'day_of_month' => 15,
            'today' => false,
            'result' => 'partial',
            'result_label' => 'Часть не принята',
        ])
        ->and($days['2026-10-15']['doses'])->toBe([
            [
                'id' => doseOn($this->patient, '2026-10-15', 'morning')->id,
                'time_of_day' => 'Утром',
                'time' => '8:00',
                'status' => 'taken',
                'status_text' => 'Принят поздно, в 9:30',
                'unwell' => false,
                'skip_comment' => null,
                'marked_late' => false,
                'marked_at' => null,
            ],
            [
                'id' => doseOn($this->patient, '2026-10-15', 'evening')->id,
                'time_of_day' => 'Вечером',
                'time' => '20:00',
                'status' => 'skipped',
                'status_text' => 'Не принят: плохо себя чувствовал или были побочные эффекты',
                'unwell' => true,
                'skip_comment' => 'Тошнило',
                'marked_late' => false,
                'marked_at' => null,
            ],
        ])
        ->and($days['2026-10-16']['doses'][0])->toMatchArray([
            'status_text' => 'Принят в 20:00',
            'marked_late' => true,
            'marked_at' => '17.10 в 9:05',
        ])
        ->and(array_map(fn (array $dose): array => [$dose['status_text'], $dose['marked_late'], $dose['marked_at']], $days['2026-10-17']['doses']))->toBe([
            ['Принят поздно, в 15:00', true, null],
            ['Без отметки', false, null],
        ])
        ->and($days['2026-10-20'])->toMatchArray(['today' => true, 'result' => 'pending'])
        ->and($days['2026-10-20']['doses'][0]['status_text'])->toBe('Запланирован');
});

test('the strip shows the last 28 days of the course', function () {
    $active = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-09-01');
    $finished = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-07-01', ['ends_on' => '2026-09-05']);
    adherenceDose($active, '2026-09-22');
    adherenceDose($active, '2026-09-23');

    $adherence = adherenceOf($this->patient);
    $days = $adherence['courses'][0]['days'];

    expect($days)->toHaveCount(28)
        ->and($days[0]['date'])->toBe('2026-09-23')
        ->and($days[27]['date'])->toBe('2026-10-20')
        ->and($days[0]['result'])->toBe('on_time')
        ->and(array_column($adherence['finished'][0]['days'], 'date'))->toHaveCount(28)
        ->and($adherence['finished'][0]['days'][27]['date'])->toBe('2026-09-05');
});

test('days without planned doses are not counted and warn when a time is not chosen', function () {
    // Утренний приём со временем, у вечернего пациент время не выбрал.
    $course = regularPrescription($this->patient, $this->aviandr, ['morning' => '1', 'evening' => '1'], [
        'prescriber_id' => $this->doctor->id,
        'starts_on' => '2026-10-17',
    ]);
    $course->intakes()->where('time_of_day', 'morning')->update(['time' => '08:00', 'time_set_at' => '2026-10-18 10:00']);
    adherenceDose($course, '2026-10-19', 'missed');
    adherenceDose($course, '2026-10-20', 'missed');
    // У завершённого курса предупреждения нет.
    regularPrescription($this->patient, $this->sertraline, attributes: [
        'prescriber_id' => $this->doctor->id,
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-10',
    ]);

    $adherence = adherenceOf($this->patient);

    expect($adherence['courses'][0])->toMatchArray(['missing_times' => true])
        ->and($adherence['courses'][0]['periods'][0])->toMatchArray([
            'share' => 0,
            'counted' => 2,
            'no_data_days' => 2,
            'by_time_of_day' => null,
        ])
        ->and(array_column($adherence['courses'][0]['days'], 'result'))->toBe(['no_data', 'no_data', 'none', 'none'])
        ->and($adherence['finished'][0])->toMatchArray(['missing_times' => false])
        ->and($adherence['finished'][0]['periods'][0])->toMatchArray(['share' => null, 'counted' => 0, 'no_data_days' => 3]);
});

test('days are counted by the local date of a patient far from Moscow', function () {
    // 13:00 UTC 20 октября — уже 1:00 21 октября на Камчатке.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 13:00', 'UTC'));
    $this->patient->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-15');
    adherenceDose($course, '2026-10-20', 'taken', 'evening');
    adherenceDose($course, '2026-10-21', 'scheduled');
    // Плановые приёмы на завтра в блок не попадают.
    adherenceDose($course, '2026-10-22', 'scheduled');

    $adherence = adherenceOf($this->patient);
    $days = collect($adherence['courses'][0]['days'])->keyBy('date');

    expect($adherence['timezone'])->toBe('Камчатка (UTC+12)')
        ->and($adherence['courses'][0]['day'])->toBe(7)
        ->and($days->keys()->last())->toBe('2026-10-21')
        ->and($days['2026-10-20']['result'])->toBe('on_time')
        ->and($days['2026-10-20']['doses'][0])->toMatchArray(['time' => '20:00', 'status_text' => 'Принят в 20:00'])
        ->and($days['2026-10-21'])->toMatchArray(['today' => true, 'result' => 'pending']);
});

test('the timezone caption is shown only for a patient outside Moscow', function () {
    expect(adherenceOf($this->patient)['timezone'])->toBeNull();
});

test('a share by time of day is shown when the period has doses at different times', function () {
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');

    foreach (['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $i => $date) {
        adherenceDose($course, $date);
        adherenceDose($course, $date, $i % 2 === 0 ? 'missed' : 'taken', 'evening');
    }

    expect(adherenceOf($this->patient)['courses'][0]['periods'][0]['by_time_of_day'])->toBe([
        ['label' => 'утром', 'share' => 100],
        ['label' => 'вечером', 'share' => 42],
    ]);
});

test('an as needed course counts days with doses and days over the limit of the version of that day', function () {
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
    $first = Prescription::factory()->for($this->patient, 'patient')->for($hydroxyzine, 'strength')->asNeeded('1', 2)->create([
        'prescriber_id' => $this->doctor->id,
        'starts_on' => '2026-10-14',
        ...closedAt($this->doctor, '2026-10-17 07:00', PrescriptionCloseReason::Changed),
    ]);
    $second = Prescription::factory()->for($this->patient, 'patient')->for($hydroxyzine, 'strength')->asNeeded('1', 1)->create([
        'prescriber_id' => $this->doctor->id,
        'previous_id' => $first->id,
        'starts_on' => '2026-10-17',
    ]);
    asNeededDosesOn($first, '2026-10-14', 3);
    asNeededDosesOn($first, '2026-10-15', 2);
    // В день изменения действует лимит новой версии.
    asNeededDosesOn($first, '2026-10-17', 1);
    asNeededDosesOn($second, '2026-10-17', 1);
    asNeededDosesOn($second, '2026-10-18', 1);

    $course = adherenceOf($this->patient)['courses'][0];
    $days = collect($course['days'])->keyBy('date');

    expect($course)->toMatchArray(['id' => $first->id, 'kind' => 'as_needed', 'kind_label' => 'По необходимости', 'missing_times' => false])
        ->and($course['periods'])->toBe([[
            'days' => 'Дни 1–7',
            'dates' => '14–20 октября',
            'current' => true,
            'days_with_doses' => 4,
            'doses' => 8,
            'days_over_limit' => 2,
        ]])
        ->and($days)->toHaveCount(7)
        ->and($days['2026-10-14'])->toMatchArray(['count' => 3, 'over_limit' => true, 'times' => ['9:00', '10:00', '11:00']])
        ->and($days['2026-10-15'])->toMatchArray(['count' => 2, 'over_limit' => false])
        ->and($days['2026-10-16'])->toMatchArray(['count' => 0, 'over_limit' => false, 'times' => []])
        ->and($days['2026-10-17'])->toMatchArray(['count' => 2, 'over_limit' => true])
        ->and($days['2026-10-18'])->toMatchArray(['count' => 1, 'over_limit' => false]);
});

test('medications added by the patient follow the doctor courses, only current ones', function () {
    adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');
    $regular = ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-18']);
    $unlimited = ownPrescription($this->patient, $this->sertraline->drug, [], ['kind' => 'as_needed', 'starts_on' => '2026-10-19']);
    $limited = ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'drug_name' => 'Афобазол', 'max_daily_doses' => 1, 'starts_on' => '2026-10-19']);
    // Прекращённый, завершённый и ещё не начавшийся не показываются.
    ownPrescription($this->patient, attributes: ['drug_name' => 'Глицин', 'starts_on' => '2026-10-01', ...closedAt($this->patient, '2026-10-15 09:00')]);
    ownPrescription($this->patient, attributes: ['drug_name' => 'Магне B6', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-10']);
    ownPrescription($this->patient, attributes: ['drug_name' => 'Новопассит', 'starts_on' => '2026-10-25']);
    adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-25');
    adherenceDose($regular, '2026-10-19', 'skipped');
    asNeededDosesOn($unlimited, '2026-10-19', 5);
    asNeededDosesOn($limited, '2026-10-19', 2);

    $adherence = adherenceOf($this->patient);

    expect(array_column($adherence['courses'], 'drug'))->toBe(['Авиандр'])
        ->and($adherence['finished'])->toBe([])
        ->and(array_map(fn (array $course): array => [$course['drug'], $course['kind'], $course['source']], $adherence['own']))->toBe([
            ['Афобазол', 'as_needed', 'patient'],
            ['Сертралин', 'as_needed', 'patient'],
            ['Фенибут', 'regular', 'patient'],
        ])
        ->and(collect($adherence['own'][0]['days'])->firstWhere('date', '2026-10-19'))->toMatchArray(['count' => 2, 'over_limit' => true])
        ->and(collect($adherence['own'][1]['days'])->firstWhere('date', '2026-10-19'))->toMatchArray(['count' => 5, 'over_limit' => false])
        ->and($adherence['own'][2]['periods'][0])->toMatchArray(['share' => 0, 'counted' => 1]);
});

test('there are no courses before the first prescription starts', function () {
    adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-25');
    // Отменён до начала.
    adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-10-21', closedAt($this->doctor, '2026-10-19 09:00'));

    expect(adherenceOf($this->patient))->toBe([
        'timezone' => null,
        'courses' => [],
        'finished' => [],
        'own' => [],
    ]);
});

test('the patient card passes the intake block', function () {
    adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('adherence.courses', 1)
            ->where('adherence.courses.0.drug', 'Авиандр')
            ->where('adherence.timezone', null)
            ->where('adherence.finished', [])
            ->where('adherence.own', []),
        );
});

test('the card of a foreign patient is not found', function () {
    $foreign = User::factory()->patientOf(User::factory()->doctor()->create())->create();
    adherenceCourse($this->doctor, $foreign, $this->aviandr, '2026-10-14');

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $foreign))
        ->assertNotFound();
});

test('the number of card queries does not grow with courses and days', function () {
    $hydroxyzine = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
    $course = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-14');
    $own = ownPrescription($this->patient, $this->sertraline->drug, attributes: ['starts_on' => '2026-10-14']);
    adherenceDose($course, '2026-10-19');
    $this->actingAs($this->doctor)->get(route('doctor.patients.show', $this->patient))->assertOk();

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('doctor.patients.show', $this->patient))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $few = $queries();

    // Ещё курсы: изменённый, завершённый, «по необходимости» — и приёмы за два месяца.
    $changed = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-08-20', closedAt($this->doctor, '2026-09-10 07:00', PrescriptionCloseReason::Changed));
    $current = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-09-10', ['previous_id' => $changed->id]);
    $finished = adherenceCourse($this->doctor, $this->patient, $hydroxyzine, '2026-08-01', ['ends_on' => '2026-09-01']);
    $asNeeded = Prescription::factory()->for($this->patient, 'patient')->for($hydroxyzine, 'strength')->asNeeded('1', 2)
        ->create(['prescriber_id' => $this->doctor->id, 'starts_on' => '2026-08-20']);

    for ($date = CarbonImmutable::parse('2026-08-20', 'UTC'); $date->lte(CarbonImmutable::parse('2026-10-20', 'UTC')); $date = $date->addDay()) {
        $version = $date->lt(CarbonImmutable::parse('2026-09-10', 'UTC')) ? $changed : $current;
        adherenceDose($version, $date->toDateString(), $date->day % 3 === 0 ? 'missed' : 'taken');
        adherenceDose($version, $date->toDateString(), 'taken', 'evening');
        asNeededDosesOn($asNeeded, $date->toDateString(), $date->day % 4);

        if ($date->lte(CarbonImmutable::parse('2026-09-01', 'UTC'))) {
            adherenceDose($finished, $date->toDateString());
        }

        if ($date->gte(CarbonImmutable::parse('2026-10-14', 'UTC'))) {
            adherenceDose($own, $date->toDateString());
        }
    }

    expect($queries())->toBe($few);
});
