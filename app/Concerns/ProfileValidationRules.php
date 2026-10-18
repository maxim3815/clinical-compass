<?php

namespace App\Concerns;

use App\Enums\RussianTimezone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(): array
    {
        return [
            'name' => $this->nameRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Дата рождения пациента, необязательная.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function birthDateRules(): array
    {
        return ['nullable', 'date', 'after:1900-01-01', 'before:today'];
    }

    /**
     * Часовой пояс пациента: только из списка часовых поясов России.
     *
     * @return array<int, ValidationRule|array<mixed>|string|Enum>
     */
    protected function timezoneRules(): array
    {
        return ['required', Rule::enum(RussianTimezone::class)];
    }
}
