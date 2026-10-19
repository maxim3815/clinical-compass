<?php

namespace App\Enums;

/**
 * Время суток приёма в назначении. Точное время выбирает пациент.
 * Порядок вариантов — порядок приёмов в течение дня.
 */
enum TimeOfDay: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Bedtime = 'bedtime';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Утром',
            self::Afternoon => 'Днём',
            self::Evening => 'Вечером',
            self::Bedtime => 'Перед сном',
        };
    }

    /**
     * Подсказка в форме выбора времени приёма.
     */
    public function defaultTime(): string
    {
        return match ($this) {
            self::Morning => '08:00',
            self::Afternoon => '14:00',
            self::Evening => '20:00',
            self::Bedtime => '22:00',
        };
    }

    /**
     * Место в течение дня, чтобы сортировать приёмы.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $timeOfDay): array => ['value' => $timeOfDay->value, 'label' => $timeOfDay->label()],
            self::cases(),
        );
    }
}
