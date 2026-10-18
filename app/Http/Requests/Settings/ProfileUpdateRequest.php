<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->user()?->role !== UserRole::Patient) {
            return $this->profileRules();
        }

        // Не присланное поле пациента остаётся прежним, присланное проверяется.
        return [
            ...$this->profileRules(),
            'birth_date' => ['sometimes', ...$this->birthDateRules()],
            'timezone' => ['sometimes', ...$this->timezoneRules()],
        ];
    }
}
