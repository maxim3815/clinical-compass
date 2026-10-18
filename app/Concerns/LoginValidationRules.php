<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait LoginValidationRules
{
    /**
     * Правила для логина нового пользователя. Логин проверяется уже приведённым к нижнему регистру.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function loginRules(): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:32',
            'regex:/^[a-z0-9._-]+$/',
            Rule::unique(User::class, 'login'),
        ];
    }
}
