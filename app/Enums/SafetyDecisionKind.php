<?php

namespace App\Enums;

/**
 * Как врач решил по красной находке: назначил или изменил назначение с обоснованием
 * либо продолжил приём с обоснованием.
 */
enum SafetyDecisionKind: string
{
    case Prescribed = 'prescribed';
    case Continued = 'continued';

    public function label(): string
    {
        return match ($this) {
            self::Prescribed => 'Назначено вопреки противопоказанию',
            self::Continued => 'Приём продолжен',
        };
    }
}
