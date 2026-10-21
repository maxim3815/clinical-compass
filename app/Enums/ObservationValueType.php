<?php

namespace App\Enums;

/**
 * Вид значения факта о пациенте: «да / нет», число с единицей или вариант из списка.
 */
enum ObservationValueType: string
{
    case Boolean = 'boolean';
    case Number = 'number';
    case Choice = 'choice';

    /**
     * Поле observations, в котором лежит значение этого вида.
     */
    public function column(): string
    {
        return match ($this) {
            self::Boolean => 'value_boolean',
            self::Number => 'value_number',
            self::Choice => 'value_choice',
        };
    }
}
