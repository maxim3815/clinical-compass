<?php

use App\Enums\DoseSkipReason;
use App\Enums\Sex;
use App\Models\CareRelationship;
use App\Models\User;
use App\Support\Adherence\IntakeCalendar;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве, вторник 20 октября.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
    $this->sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();
});

/**
 * Месяц календаря пациента на его сегодняшнюю дату.
 *
 * @return array<string, mixed>
 */
function calendarOf(User $patient, ?string $month = null): array
{
    $patient = $patient->fresh() ?? $patient;

    return IntakeCalendar::present($patient, $month, $patient->localToday());
}

/**
 * Дни месяца по дате.
 *
 * @return array<string, array<string, mixed>>
 */
function calendarDays(array $calendar): array
{
    return collect($calendar['days'])->keyBy('date')->all();
}

test('the day result covers every scheduled dose of the patient, own medications and finished courses included', function () {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    $sertraline = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2026-09-25', closedAt($this->doctor, '2026-10-03 18:00'), ['morning' => '08:00']);
    $own = ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-01']);

    // Всё принято, вечерний приём поздно: у пациента это тоже «всё принято».
    adherenceDose($aviandr, '2026-10-02');
    adherenceDose($aviandr, '2026-10-02', 'taken', 'evening', ['taken_at' => CarbonImmutable::parse('2026-10-02 23:30', 'Europe/Moscow')->utc()]);
    // Не принят приём отменённого потом курса.
    adherenceDose($aviandr, '2026-10-03');
    adherenceDose($sertraline, '2026-10-03', 'skipped');
    // Ничего не принято.
    adherenceDose($aviandr, '2026-10-04', 'missed');
    adherenceDose($aviandr, '2026-10-04', 'skipped', 'evening');
    // Не принят свой препарат.
    adherenceDose($aviandr, '2026-10-06');
    adherenceDose($own, '2026-10-06', 'missed');
    // Сегодня: утро принято, вечер впереди.
    adherenceDose($aviandr, '2026-10-20');
    adherenceDose($aviandr, '2026-10-20', 'scheduled', 'evening');

    $calendar = calendarOf($this->patient);
    $days = calendarDays($calendar);

    expect($calendar)->toMatchArray([
        'month' => '2026-10',
        'title' => 'Октябрь 2026',
        'in_month' => 'октябре',
        // 1 октября 2026 — четверг.
        'offset' => 3,
        'taken' => 5,
        'counted' => 9,
    ])
        ->and($calendar['days'])->toHaveCount(31)
        ->and($days['2026-10-02']['result'])->toBe('taken')
        ->and($days['2026-10-03']['result'])->toBe('partial')
        ->and($days['2026-10-04']['result'])->toBe('none')
        ->and($days['2026-10-05']['result'])->toBe('no_data')
        ->and($days['2026-10-06']['result'])->toBe('partial')
        ->and($days['2026-10-20'])->toMatchArray(['result' => 'pending', 'today' => true, 'future' => false, 'label' => 'Вторник, 20 октября'])
        ->and($days['2026-10-21'])->toMatchArray(['result' => null, 'future' => true, 'doses' => []]);
});

test('the day lists doses by time in the words of the patient', function () {
    $this->patient->patientProfile->update(['sex' => Sex::Female]);
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    $own = ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-01', 'dose' => '1 таблетка 10 мг']);

    adherenceDose($aviandr, '2026-10-19', 'taken', 'morning', ['taken_at' => CarbonImmutable::parse('2026-10-19 08:40', 'Europe/Moscow')->utc()]);
    adherenceDose($own, '2026-10-19', 'missed');
    adherenceDose($aviandr, '2026-10-19', 'skipped', 'evening', ['skip_reason' => DoseSkipReason::Unwell, 'skip_comment' => 'Тошнило весь вечер']);
    adherenceDose($aviandr, '2026-10-20', 'scheduled', 'evening');

    $days = calendarDays(calendarOf($this->patient));

    expect($days['2026-10-19']['doses'])->toHaveCount(3)
        ->and(array_map(fn (array $dose): array => [$dose['time'], $dose['drug'], $dose['dose'], $dose['status_text']], $days['2026-10-19']['doses']))->toBe([
            ['8:00', 'Авиандр', '1 таблетка, 20 мг', 'Принят в 8:40'],
            ['8:00', 'Фенибут', '1 таблетка 10 мг', 'Нет отметки'],
            ['20:00', 'Авиандр', '1 таблетка, 20 мг', 'Не принят: плохо себя чувствовала или были побочные эффекты'],
        ])
        ->and($days['2026-10-19']['doses'][2]['skip_comment'])->toBe('Тошнило весь вечер')
        ->and($days['2026-10-19']['unwell'])->toBeTrue()
        ->and($days['2026-10-20']['doses'][0]['status_text'])->toBe('Ещё не отмечен')
        ->and($days['2026-10-20']['unwell'])->toBeFalse();
});

test('as needed doses are listed in the day and do not change its result', function () {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01', [], ['morning' => '08:00']);
    $hydroxyzine = ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'drug_name' => 'Гидроксизин', 'max_daily_doses' => 2, 'starts_on' => '2026-10-01']);
    $phenibut = ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'starts_on' => '2026-10-01']);

    adherenceDose($aviandr, '2026-10-14');
    asNeededDosesOn($hydroxyzine, '2026-10-14', 3);
    asNeededDosesOn($phenibut, '2026-10-14', 1);
    asNeededDosesOn($hydroxyzine, '2026-10-15', 1);

    $days = calendarDays(calendarOf($this->patient));

    expect($days['2026-10-14']['result'])->toBe('taken')
        ->and($days['2026-10-14']['as_needed'])->toBe([
            ['drug' => 'Гидроксизин', 'count' => 3, 'times' => ['9:00', '10:00', '11:00'], 'over_limit' => 2],
            ['drug' => 'Фенибут', 'count' => 1, 'times' => ['9:00'], 'over_limit' => null],
        ])
        ->and($days['2026-10-15']['result'])->toBe('no_data')
        ->and($days['2026-10-15']['as_needed'][0])->toMatchArray(['count' => 1, 'over_limit' => null]);
});

test('marking from the calendar is offered for today and yesterday with unmarked doses', function () {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');

    adherenceDose($aviandr, '2026-10-18', 'missed');
    adherenceDose($aviandr, '2026-10-19', 'missed');
    adherenceDose($aviandr, '2026-10-20');
    adherenceDose($aviandr, '2026-10-20', 'scheduled', 'evening');

    $days = calendarDays(calendarOf($this->patient));

    expect($days['2026-10-19']['yesterday'])->toBeTrue()
        ->and($days['2026-10-20']['yesterday'])->toBeFalse()
        ->and($days['2026-10-18']['can_mark'])->toBeFalse()
        ->and($days['2026-10-19']['can_mark'])->toBeTrue()
        ->and($days['2026-10-20']['can_mark'])->toBeTrue();

    $this->patient->doseEvents()->update(['status' => 'taken', 'taken_at' => now(), 'marked_at' => now()]);
    $days = calendarDays(calendarOf($this->patient));

    expect($days['2026-10-19']['can_mark'])->toBeFalse()
        ->and($days['2026-10-20']['can_mark'])->toBeFalse();
});

test('months go from the first dose of the patient to the current month', function (?string $month, string $shown, ?string $previous, ?string $next) {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-08-15');
    adherenceDose($aviandr, '2026-08-15');

    expect(calendarOf($this->patient, $month))->toMatchArray(['month' => $shown, 'previous' => $previous, 'next' => $next]);
})->with([
    'current' => [null, '2026-10', '2026-09', null],
    'first' => ['2026-08', '2026-08', null, '2026-09'],
    'before the first dose' => ['2026-07', '2026-08', null, '2026-09'],
    'in the future' => ['2027-01', '2026-10', '2026-09', null],
    'not a month' => ['2026-13', '2026-10', '2026-09', null],
    'garbage' => ['октябрь', '2026-10', '2026-09', null],
]);

test('a month of another year names the year', function () {
    $sertraline = adherenceCourse($this->doctor, $this->patient, $this->sertraline, '2025-12-30', [], ['morning' => '08:00']);
    adherenceDose($sertraline, '2025-12-31');

    $calendar = calendarOf($this->patient, '2025-12');

    expect($calendar)->toMatchArray(['title' => 'Декабрь 2025', 'in_month' => 'декабре 2025', 'taken' => 1, 'counted' => 1])
        ->and(calendarDays($calendar)['2025-12-31']['label'])->toBe('Среда, 31 декабря 2025');
});

test('patient sees the calendar page with own doses only', function () {
    $aviandr = adherenceCourse($this->doctor, $this->patient, $this->aviandr, '2026-10-01');
    adherenceDose($aviandr, '2026-10-19');
    $other = User::factory()->patientOf($this->doctor)->create();
    adherenceDose(adherenceCourse($this->doctor, $other, $this->aviandr, '2026-10-01'), '2026-10-19', 'missed');

    $this->actingAs($this->patient)
        ->get(route('patient.calendar', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/IntakeCalendar')
            ->where('hasHistory', true)
            ->where('hasDoctor', true)
            ->where('calendar.month', '2026-10')
            ->where('calendar.taken', 1)
            ->where('calendar.counted', 1)
            ->where('calendar.days.18.date', '2026-10-19')
            ->where('calendar.days.18.result', 'taken'),
        );
});

test('patient without doses sees an empty calendar and no link to it', function () {
    $patient = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['patient_id' => $patient->id]);

    $this->actingAs($patient)
        ->get(route('patient.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasHistory', false)
            ->where('hasDoctor', false)
            ->where('calendar.month', '2026-10')
            ->where('calendar.counted', 0),
        );

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('hasIntakeHistory', false));
});

test('the today screen links to the calendar once there are doses', function () {
    asNeededDosesOn(ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'starts_on' => '2026-10-19']), '2026-10-19', 1);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('hasIntakeHistory', true));
});

test('doctor can not open the intake calendar', function () {
    $this->actingAs($this->doctor)
        ->get(route('patient.calendar'))
        ->assertForbidden();
});
