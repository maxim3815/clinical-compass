<?php

namespace App\Enums;

/**
 * Уровень алерта. Пока один: экстренный — красная плашка со звуком у врача и пуш.
 * Уровень задаёт правило в файле.
 */
enum AlertLevel: string
{
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Срочно',
        };
    }
}
