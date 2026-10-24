<?php

namespace App\Enums;

use App\Support\Adherence\DoseTally;

/**
 * Итог дня регулярного курса для врача: клетка полосы дней в блоке «Приём препаратов».
 */
enum DoseDayResult: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Partial = 'partial';
    case None = 'none';
    case Pending = 'pending';
    case NoData = 'no_data';

    public static function of(DoseTally $tally, bool $today): self
    {
        $counted = $tally->counted();

        return match (true) {
            $counted + $tally->scheduled === 0 => self::NoData,
            // Запланированный приём в прошлом бывает только до ближайшего запуска scheduler.
            $tally->scheduled > 0 && ($today || $counted === 0) => self::Pending,
            $tally->taken === $counted => $tally->late > 0 ? self::Late : self::OnTime,
            $tally->taken === 0 => self::None,
            default => self::Partial,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'Все приняты вовремя',
            self::Late => 'Все приняты, часть поздно',
            self::Partial => 'Часть не принята',
            self::None => 'Ни один не принят',
            self::Pending => 'День ещё идёт, впереди есть приёмы',
            self::NoData => 'Нет данных: приёмы не планировались',
        };
    }
}
