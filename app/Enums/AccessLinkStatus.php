<?php

namespace App\Enums;

enum AccessLinkStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активно',
            self::Used => 'Использовано',
            self::Expired => 'Истекло',
            self::Revoked => 'Отозвано',
        };
    }
}
