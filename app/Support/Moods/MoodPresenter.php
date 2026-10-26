<?php

namespace App\Support\Moods;

use App\Models\MoodMark;
use App\Models\User;

/**
 * Настроение в карточке пациента у врача: лица по дням за две недели, у каждого — время приёма,
 * и серия грустных дней, если она есть. Пустые дни тоже видны: на них серия прерывается.
 */
final class MoodPresenter
{
    public const int DAYS = 14;

    /**
     * @return array{
     *     days: list<array{iso: string, date: string, weekday: string, long: string, today: bool, in_streak: bool, marks: list<array{slot: string, mood: string, label: string}>}>,
     *     slots: list<string>,
     *     streak: array{days: int, from: string, to: string}|null,
     *     any: bool,
     *     planned: bool,
     * }
     */
    public static function forDoctor(User $patient): array
    {
        $today = $patient->localToday();
        $from = $today->subDays(self::DAYS - 1);

        $marks = MoodMark::query()
            ->where('patient_id', $patient->id)
            ->where('local_date', '>=', $from->toDateString())
            ->where('local_date', '<=', $today->toDateString())
            ->get()
            // Время без ведущего нуля: «8:00» раньше «20:00» только по минутам, не по строке.
            ->sortBy(fn (MoodMark $mark): int => self::minutes($mark->slot))
            ->groupBy(fn (MoodMark $mark): string => $mark->local_date->toDateString());

        // Серия кончается сегодня, а если сегодня лиц ещё нет — вчера (как метка в списке).
        $streak = MoodSignals::forPatient($patient);
        $streakEnd = $marks->has($today->toDateString()) ? $today : $today->subDay();
        $streakStart = $streakEnd->subDays(max($streak, 1) - 1);
        $days = [];

        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $days[] = [
                'iso' => $day->toDateString(),
                'date' => $day->format('d.m'),
                'weekday' => $day->isoFormat('dd'),
                // Для скринридера: сокращения «вт», «чт» он читает по буквам.
                'long' => $day->isoFormat('dddd, D MMMM'),
                'today' => $day->eq($today),
                'in_streak' => $streak > 0 && $day->gte($streakStart) && $day->lte($streakEnd),
                'marks' => array_values(($marks->get($day->toDateString()) ?? collect())
                    ->map(fn (MoodMark $mark): array => [
                        'slot' => $mark->slot,
                        'mood' => $mark->mood->value,
                        'label' => $mark->mood->label(),
                    ])
                    ->all()),
            ];
        }

        return [
            'days' => $days,
            // Все времена приёма за две недели по порядку: в каждом дне лица стоят на своих строках.
            'slots' => array_values($marks->flatten(1)
                ->map(fn (MoodMark $mark): string => $mark->slot)
                ->unique()
                ->sortBy(fn (string $slot): int => self::minutes($slot))
                ->all()),
            'streak' => $streak > 0 ? [
                'days' => $streak,
                'from' => $streakStart->format('d.m'),
                'to' => $streakEnd->format('d.m'),
            ] : null,
            'any' => $marks->isNotEmpty() || $patient->moodMarks()->exists(),
            // Лица бывают только после отметок плановых приёмов.
            'planned' => $patient->doseEvents()->exists(),
        ];
    }

    private static function minutes(string $slot): int
    {
        [$hours, $minutes] = explode(':', $slot);

        return (int) $hours * 60 + (int) $minutes;
    }
}
