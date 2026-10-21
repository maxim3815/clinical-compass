<?php

namespace App\Enums;

enum SafetyRuleType: string
{
    case Contraindication = 'contraindication';
    case Interaction = 'interaction';
    case Caution = 'caution';
    case Indication = 'indication';

    public function label(): string
    {
        return match ($this) {
            self::Contraindication => 'Противопоказание',
            self::Interaction => 'Взаимодействие',
            self::Caution => 'С осторожностью',
            self::Indication => 'Показания',
        };
    }
}
