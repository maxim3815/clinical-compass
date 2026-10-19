<?php

namespace App\Enums;

enum PrescriptionKind: string
{
    case Regular = 'regular';
    case AsNeeded = 'as_needed';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Регулярно',
            self::AsNeeded => 'По необходимости',
        };
    }
}
