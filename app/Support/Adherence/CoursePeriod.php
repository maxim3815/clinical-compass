<?php

namespace App\Support\Adherence;

use Carbon\CarbonImmutable;

/**
 * Контрольный период курса по клиническим рекомендациям: дни 1–7, 8–14, 15–28,
 * дальше каждые 28 дней. Последний период обрезан датой окончания курса.
 */
final class CoursePeriod
{
    private function __construct(
        public readonly int $firstDay,
        public readonly int $lastDay,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly bool $current,
    ) {}

    /**
     * Периоды, которые уже начались, по порядку: первый — с начала курса.
     *
     * @return list<self>
     */
    public static function forCourse(Course $course, CarbonImmutable $today): array
    {
        $periods = [];
        $lastDay = $course->dayNumber($course->lastDay($today));
        $finished = $course->isFinished($today);

        for ($index = 0; ; $index++) {
            [$firstDay, $periodLastDay] = self::bounds($index);

            if ($firstDay > $lastDay) {
                return $periods;
            }

            $from = $course->start->addDays($firstDay - 1);
            $to = $course->start->addDays($periodLastDay - 1);

            if ($course->end !== null && $course->end->lt($to)) {
                $to = $course->end;
            }

            $periods[] = new self(
                $firstDay,
                $course->dayNumber($to),
                $from,
                $to,
                ! $finished && $today->betweenIncluded($from, $to),
            );
        }
    }

    /**
     * Первый и последний день периода по его номеру с нуля.
     *
     * @return array{int, int}
     */
    private static function bounds(int $index): array
    {
        return match ($index) {
            0 => [1, 7],
            1 => [8, 14],
            2 => [15, 28],
            default => [29 + 28 * ($index - 3), 56 + 28 * ($index - 3)],
        };
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->from, $this->to);
    }
}
