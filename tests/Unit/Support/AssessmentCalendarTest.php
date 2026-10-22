<?php

use App\Support\Assessments\AssessmentCalendar;
use Carbon\CarbonImmutable;

// Календарь опросников: дни 0, 7, 14, 28, дальше каждые 28 дней от начала наблюдения.

function assessmentCalendar(): AssessmentCalendar
{
    return new AssessmentCalendar([0, 7, 14, 28], 28);
}

function calendarDay(int $offset): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-01', 'UTC')->addDays($offset);
}

test('current due date is the last date of the calendar that has come', function (int $today, ?int $due) {
    expect(assessmentCalendar()->currentDue(calendarDay(0), calendarDay($today))?->toDateString())->toBe($due === null ? null : calendarDay($due)->toDateString());
})->with([
    'before observation' => [-1, null],
    'registration day' => [0, 0],
    'day 6' => [6, 0],
    'day 7' => [7, 7],
    'day 13' => [13, 7],
    'day 14' => [14, 14],
    'day 27' => [27, 14],
    'day 28' => [28, 28],
    'day 55' => [55, 28],
    'day 56' => [56, 56],
    'day 111' => [111, 84],
    'day 112' => [112, 112],
]);

test('due dates list every date that has come', function () {
    expect(array_map(fn (CarbonImmutable $date): string => $date->toDateString(), assessmentCalendar()->dueDates(calendarDay(0), calendarDay(60))))
        ->toBe(['2026-10-01', '2026-10-08', '2026-10-15', '2026-10-29', '2026-11-26']);
});

test('a filling closes the due date on that day, later or up to three days before', function (int $filled, bool $closed) {
    expect(AssessmentCalendar::isClosed(calendarDay(14), [calendarDay($filled)]))->toBe($closed);
})->with([
    'four days before' => [10, false],
    'three days before' => [11, true],
    'day before' => [13, true],
    'on the day' => [14, true],
    'later' => [20, true],
]);

test('open due date waits for a filling and a missed date gives one card', function () {
    // Регистрация заполнена, срок 7-го дня пропущен и догнан сроком 14-го: карточка одна, за 14-й день.
    expect(assessmentCalendar()->openDue(calendarDay(0), calendarDay(0), [])?->toDateString())->toBe(calendarDay(0)->toDateString())
        ->and(assessmentCalendar()->openDue(calendarDay(0), calendarDay(3), [calendarDay(0)]))->toBeNull()
        ->and(assessmentCalendar()->openDue(calendarDay(0), calendarDay(20), [calendarDay(0)])?->toDateString())->toBe(calendarDay(14)->toDateString())
        ->and(assessmentCalendar()->openDue(calendarDay(0), calendarDay(20), [calendarDay(0), calendarDay(18)]))->toBeNull();
});

test('next due date skips a date already closed by an early filling', function () {
    expect(assessmentCalendar()->nextDue(calendarDay(0), calendarDay(8), [calendarDay(0), calendarDay(7)])->toDateString())->toBe(calendarDay(14)->toDateString())
        // Заполнено за два дня до 14-го: следующий срок — 28-й день.
        ->and(assessmentCalendar()->nextDue(calendarDay(0), calendarDay(12), [calendarDay(0), calendarDay(7), calendarDay(12)])->toDateString())->toBe(calendarDay(28)->toDateString())
        ->and(assessmentCalendar()->nextDue(calendarDay(0), calendarDay(112), [calendarDay(111)])->toDateString())->toBe(calendarDay(140)->toDateString());
});
