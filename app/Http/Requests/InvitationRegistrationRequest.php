<?php

namespace App\Http\Requests;

use App\Concerns\LoginValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class InvitationRegistrationRequest extends FormRequest
{
    use LoginValidationRules, PasswordValidationRules, ProfileValidationRules;

    /**
     * Форму проверяем только у гостя и только для действующей ссылки, иначе по ошибкам
     * валидации можно было бы перебирать токены. В остальных случаях страница ссылки
     * сама покажет, что делать.
     */
    public function authorize(): bool
    {
        return $this->user() === null
            && AccessLink::findActive(AccessLinkPurpose::Invitation, $this->token()) !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'login' => $this->loginRules(),
            'password' => $this->passwordRules(),
            'birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'consent' => ['accepted'],
        ];
    }

    public function token(): string
    {
        $token = $this->route('token');

        return is_string($token) ? $token : '';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login'))) {
            $this->merge(['login' => Str::lower($this->input('login'))]);
        }
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(to_route('invitation.show', $this->token()));
    }
}
