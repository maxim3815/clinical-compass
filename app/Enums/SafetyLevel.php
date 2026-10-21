<?php

namespace App\Enums;

/**
 * Уровень находки. Красный «Противопоказано» требует обоснования врача, жёлтый — нет.
 * Уровень задаёт правило, а не его тип.
 */
enum SafetyLevel: string
{
    case Contraindicated = 'contraindicated';
    case Caution = 'caution';

    public function label(): string
    {
        return match ($this) {
            self::Contraindicated => 'Противопоказано',
            self::Caution => 'С осторожностью',
        };
    }

    public function requiresJustification(): bool
    {
        return $this === self::Contraindicated;
    }
}
