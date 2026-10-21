<?php

namespace App\Enums;

enum Sex: string
{
    case Female = 'female';
    case Male = 'male';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Женский',
            self::Male => 'Мужской',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $sex): array => ['value' => $sex->value, 'label' => $sex->label()],
            self::cases(),
        );
    }
}
