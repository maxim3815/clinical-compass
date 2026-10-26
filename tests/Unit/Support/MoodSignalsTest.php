<?php

use App\Support\Moods\MoodSignals;
use Carbon\CarbonImmutable;

/**
 * Дни как в MoodSignals: местная дата => все лица дня грустные.
 *
 * @param  array<int, bool>  $byDaysAgo  сколько дней назад => грустный ли день
 * @return array<string, bool>
 */
function moodDays(array $byDaysAgo): array
{
    $today = CarbonImmutable::parse('2026-10-19');
    $days = [];

    foreach ($byDaysAgo as $ago => $sad) {
        $days[$today->subDays($ago)->toDateString()] = $sad;
    }

    return $days;
}

test('three sad days in a row ending today or yesterday raise the mark', function (array $days, int $streak) {
    expect(MoodSignals::streak(moodDays($days), CarbonImmutable::parse('2026-10-19')))->toBe($streak);
})->with([
    'ending today' => [[0 => true, 1 => true, 2 => true], 3],
    'ending yesterday, today not answered yet' => [[1 => true, 2 => true, 3 => true], 3],
    'longer streak counts every day' => [[0 => true, 1 => true, 2 => true, 3 => true, 4 => true], 5],
    'two days are not enough' => [[0 => true, 1 => true], 0],
    'a day without faces breaks it' => [[0 => true, 1 => true, 3 => true, 4 => true], 0],
    'a neutral face today breaks it' => [[0 => false, 1 => true, 2 => true, 3 => true], 0],
    'ended two days ago' => [[2 => true, 3 => true, 4 => true], 0],
    'no faces' => [[], 0],
]);
