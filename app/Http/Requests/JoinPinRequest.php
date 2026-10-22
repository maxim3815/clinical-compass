<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PIN врача на странице /join/<код врача>.
 */
class JoinPinRequest extends FormRequest
{
    /**
     * Вошедшему пользователю PIN не проверяем: страница сама предложит выйти.
     */
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Введите 4 цифры.',
            'pin.regex' => 'Введите 4 цифры.',
        ];
    }

    public function pin(): string
    {
        return $this->string('pin')->toString();
    }

    protected function failedAuthorization(): never
    {
        $joinCode = $this->route('joinCode');

        throw new HttpResponseException(to_route('join.show', is_string($joinCode) ? $joinCode : ''));
    }
}
