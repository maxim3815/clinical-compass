<?php

namespace App\Enums;

/**
 * Категория направления к психиатру по тяжести состояния (функция 8 кейса).
 */
enum ReferralCategory: string
{
    case Required = 'required';
    case Desirable = 'desirable';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'обязательно',
            self::Desirable => 'желательно',
        };
    }

    /**
     * Для пациента — без слова «обязательно» в приказном тоне [медик].
     */
    public function patientLabel(): string
    {
        return match ($this) {
            self::Required => 'Терапевт считает, что вам важно получить консультацию психиатра',
            self::Desirable => 'Терапевт рекомендует консультацию психиатра',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Required => 2,
            self::Desirable => 1,
        };
    }

    /**
     * Самая сильная из категорий, null — ни одной.
     *
     * @param  iterable<self>  $categories
     */
    public static function strongest(iterable $categories): ?self
    {
        $strongest = null;

        foreach ($categories as $category) {
            if ($strongest === null || $category->rank() > $strongest->rank()) {
                $strongest = $category;
            }
        }

        return $strongest;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
