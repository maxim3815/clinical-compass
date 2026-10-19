<?php

namespace App\Enums;

/**
 * Состояние планового приёма.
 */
enum DoseEventStatus: string
{
    case Scheduled = 'scheduled';
    case Taken = 'taken';
    case Skipped = 'skipped';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Запланирован',
            self::Taken => 'Принят',
            self::Skipped => 'Не принят',
            self::Missed => 'Пропущен',
        };
    }

    /**
     * Пациент отметил приём: принял или не принял.
     */
    public function isMarked(): bool
    {
        return $this === self::Taken || $this === self::Skipped;
    }
}
