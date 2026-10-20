<?php

namespace App\Support\Adherence;

use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\DoseEvent;

/**
 * Подсчёт плановых приёмов: сколько принято, не принято и пропущено. Учтённые приёмы —
 * принятые, не принятые и пропущенные; запланированный не учитывается, даже если его окно
 * уже закрылось.
 */
final class DoseTally
{
    /**
     * @param  array<string, int>  $skipped  Не принятые по причинам: значение DoseSkipReason => число.
     */
    private function __construct(
        public readonly int $taken,
        public readonly int $late,
        public readonly array $skipped,
        public readonly int $missed,
        public readonly int $scheduled,
        public readonly int $markedLate,
    ) {}

    /**
     * @param  iterable<DoseEvent>  $events
     */
    public static function of(iterable $events): self
    {
        $taken = $late = $missed = $scheduled = $markedLate = 0;
        $skipped = [];

        foreach ($events as $event) {
            switch ($event->status) {
                case DoseEventStatus::Taken:
                    $taken++;
                    break;
                case DoseEventStatus::Skipped:
                    // У не принятого приёма причина есть всегда (dose_events_mark_check).
                    $reason = ($event->skip_reason ?? DoseSkipReason::Other)->value;
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                    break;
                case DoseEventStatus::Missed:
                    $missed++;
                    break;
                case DoseEventStatus::Scheduled:
                    $scheduled++;
                    break;
            }

            if ($event->isTakenLate()) {
                $late++;
            }

            if ($event->isMarkedAfterWindow()) {
                $markedLate++;
            }
        }

        return new self($taken, $late, $skipped, $missed, $scheduled, $markedLate);
    }

    public function onTime(): int
    {
        return $this->taken - $this->late;
    }

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    /**
     * Учтённые приёмы: принятые, не принятые и пропущенные.
     */
    public function counted(): int
    {
        return $this->taken + $this->skippedTotal() + $this->missed;
    }

    /**
     * Доля принятых в процентах с округлением вниз: 100% — только когда приняты все.
     * Без учтённых приёмов доли нет.
     */
    public function share(): ?int
    {
        $counted = $this->counted();

        return $counted === 0 ? null : intdiv(100 * $this->taken, $counted);
    }
}
