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

    /**
     * Причина от лица пациента: у пациентки в женском роде («Забыла»).
     */
    public function label(bool $female = false): string
    {
        return match ($this) {
            self::Forgot => $female ? 'Забыла' : 'Забыл',
            self::Unwell => $female ? 'Плохо себя чувствовала или побочные эффекты' : 'Плохо себя чувствовал или побочные эффекты',
            self::RanOut => 'Закончился препарат',
            self::Other => 'Другое',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $female = false): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label($female)],
            self::cases(),
        );
    }
}
