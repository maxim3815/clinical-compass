<?php

namespace App\Enums;

/**
 * Состояние назначения на дату пациента. Не хранится: считается по датам и закрытию версии.
 */
enum PrescriptionStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Completed = 'completed';
    case Changed = 'changed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Ещё не началось',
            self::Active => 'Действует',
            self::Completed => 'Завершено',
            self::Changed => 'Изменено',
            self::Cancelled => 'Отменено',
        };
    }

    /**
     * Действующие и будущие назначения показываются в списке, остальные — в истории.
     */
    public function isCurrent(): bool
    {
        return $this === self::Upcoming || $this === self::Active;
    }
}
