<?php

namespace App\Support\Moods;

use App\Enums\Mood;
use App\Models\MoodMark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Метка «плохое настроение» в списке врача: грустные дни подряд. Грустный день — все лица за
 * день грустные; день без лиц серию прерывает. Метка стоит, пока последний день серии — сегодня
 * или вчера: утром, пока пациент ещё не отмечался, она не пропадает. Не алерт: без звука, пуша
 * и счётчика.
 */
final class MoodSignals
{
    /**
     * Со скольких грустных дней подряд ставится метка.
     */
    public const int STREAK_DAYS = 3;

    /**
     * Насколько назад считается серия: длиннее месяца врачу число уже не нужно.
     */
    private const int LOOKBACK_DAYS = 31;

    /**
     * @param  Collection<int, User>  $patients
     * @return array<int, int> id пациента => грустных дней подряд; пациентов без метки нет
     */
    public static function streaks(Collection $patients): array
    {
        if ($patients->isEmpty()) {
            return [];
        }

        $days = [];

        foreach (MoodMark::query()
            ->whereIn('patient_id', $patients->map(fn (User $patient): int => $patient->id))
            ->where('local_date', '>=', CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS + 1)->toDateString())
            ->get(['patient_id', 'local_date', 'mood']) as $mark) {
            $date = $mark->local_date->toDateString();
            $days[$mark->patient_id][$date] = ($days[$mark->patient_id][$date] ?? true) && $mark->mood === Mood::Sad;
        }

        $streaks = [];

        foreach ($patients as $patient) {
            $streak = self::streak($days[$patient->id] ?? [], $patient->localToday());

            if ($streak > 0) {
                $streaks[$patient->id] = $streak;
            }
        }

        return $streaks;
    }

    public static function forPatient(User $patient): int
    {
        return self::streaks(collect([$patient]))[$patient->id] ?? 0;
    }

    /**
     * Грустных дней подряд, считая от сегодня, а если сегодня лиц ещё нет — от вчера. Меньше
     * трёх — 0.
     *
     * @param  array<string, bool>  $days  местная дата => все лица дня грустные
     */
    public static function streak(array $days, CarbonImmutable $today): int
    {
        $day = isset($days[$today->toDateString()]) ? $today : $today->subDay();
        $count = 0;

        while (($days[$day->toDateString()] ?? false) === true) {
            $count++;
            $day = $day->subDay();
        }

        return $count >= self::STREAK_DAYS ? $count : 0;
    }
}
