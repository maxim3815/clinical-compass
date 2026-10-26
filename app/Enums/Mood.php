<?php

namespace App\Enums;

/**
 * Настроение, которое пациент по желанию отмечает лицом после отметки приёма.
 */
enum Mood: string
{
    case Sad = 'sad';
    case Neutral = 'neutral';
    case Happy = 'happy';

    /**
     * Подпись лица: для скринридера у пациента и для врача.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sad => 'Плохое',
            self::Neutral => 'Обычное',
            self::Happy => 'Хорошее',
        };
    }
}
