<?php

namespace App\Enums;

/**
 * Почему пациент не принял препарат.
 */
enum DoseSkipReason: string
{
    case Forgot = 'forgot';
    case Unwell = 'unwell';
    case RanOut = 'ran_out';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Forgot => 'Забыл',
            self::Unwell => 'Плохо себя чувствовал или побочные эффекты',
            self::RanOut => 'Закончился препарат',
            self::Other => 'Другое',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
