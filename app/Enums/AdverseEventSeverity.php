<?php

namespace App\Enums;

/**
 * Насколько побочный эффект мешает пациенту [медик].
 */
enum AdverseEventSeverity: string
{
    case Mild = 'mild';
    case Moderate = 'moderate';
    case Severe = 'severe';

    /**
     * Вариант в форме пациента.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mild => 'Слабо, не мешает',
            self::Moderate => 'Мешает, но терпимо',
            self::Severe => 'Сильно, не могу жить как обычно',
        };
    }

    /**
     * Коротко, в списке пациента и у врача: «Сонливость, сильно».
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Mild => 'слабо',
            self::Moderate => 'мешает',
            self::Severe => 'сильно',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $severity): array => ['value' => $severity->value, 'label' => $severity->label()],
            self::cases(),
        );
    }
}
