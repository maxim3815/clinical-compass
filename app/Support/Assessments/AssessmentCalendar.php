<?php

namespace App\Support\Assessments;

use Carbon\CarbonImmutable;

/**
 * Календарь заполнения опросника: сроки в днях от начала наблюдения у врача (дни 0, 7, 14, 28),
 * дальше каждые thenEvery дней. Срок закрывает заполнение в день срока или позже, а также
 * не раньше чем за EARLY_DAYS дней до него. Пропущенный срок не копится: важен только последний
 * наступивший. Все даты — местные даты пациента как полночь UTC (DisplayTime::today()).
 */
final readonly class AssessmentCalendar
{
    /**
     * За сколько дней до срока заполнение уже закрывает его.
     */
    public const int EARLY_DAYS = 3;

    /**
     * @param  list<int>  $days  сроки по возрастанию, первый — 0
     */
    public function __construct(
        public array $days,
        public int $thenEvery,
    ) {}

    /**
     * Последний наступивший срок, null — если наблюдение ещё не началось.
     */
    public function currentDue(CarbonImmutable $start, CarbonImmutable $today): ?CarbonImmutable
    {
        $elapsed = (int) $start->diffInDays($today, false);

        if ($elapsed < 0) {
            return null;
        }

        $offsets = $this->offsetsUpTo($elapsed);

        return $start->addDays($offsets === [] ? 0 : max($offsets));
    }

    /**
     * Все наступившие сроки по порядку.
     *
     * @return list<CarbonImmutable>
     */
    public function dueDates(CarbonImmutable $start, CarbonImmutable $today): array
    {
        $elapsed = (int) $start->diffInDays($today, false);

        return $elapsed < 0 ? [] : array_map(fn (int $day): CarbonImmutable => $start->addDays($day), $this->offsetsUpTo($elapsed));
    }

    /**
     * Первый срок позже сегодняшнего дня, который заполнения ещё не закрыли.
     *
     * @param  list<CarbonImmutable>  $filledOn  даты заполнений
     */
    public function nextDue(CarbonImmutable $start, CarbonImmutable $today, array $filledOn): CarbonImmutable
    {
        $elapsed = max(0, (int) $start->diffInDays($today, false));
        // Заполнения закрывают сроки не дальше чем на EARLY_DAYS вперёд, поэтому открытый срок
        // найдётся в пределах двух повторов после последнего срока из списка.
        $horizon = $elapsed + max([0, ...$this->days]) + 2 * $this->thenEvery;

        foreach ($this->offsetsUpTo($horizon) as $day) {
            $due = $start->addDays($day);

            if ($due->gt($today) && ! self::isClosed($due, $filledOn)) {
                return $due;
            }
        }

        return $start->addDays($horizon);
    }

    /**
     * Срок, который наступил и не закрыт заполнением, или null, если заполнять пока не нужно.
     *
     * @param  list<CarbonImmutable>  $filledOn
     */
    public function openDue(CarbonImmutable $start, CarbonImmutable $today, array $filledOn): ?CarbonImmutable
    {
        $due = $this->currentDue($start, $today);

        return $due === null || self::isClosed($due, $filledOn) ? null : $due;
    }

    /**
     * Закрывает ли срок хотя бы одно заполнение: в день срока, позже или за EARLY_DAYS до него.
     *
     * @param  list<CarbonImmutable>  $filledOn
     */
    public static function isClosed(CarbonImmutable $due, array $filledOn): bool
    {
        $from = $due->subDays(self::EARLY_DAYS);

        foreach ($filledOn as $date) {
            if ($date->gte($from)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сроки в днях от начала наблюдения, не дальше $limit.
     *
     * @return list<int>
     */
    private function offsetsUpTo(int $limit): array
    {
        $offsets = array_values(array_filter($this->days, fn (int $day): bool => $day <= $limit));
        $last = max([0, ...$this->days]);

        for ($day = $last + $this->thenEvery; $day <= $limit; $day += $this->thenEvery) {
            $offsets[] = $day;
        }

        return $offsets;
    }
}
