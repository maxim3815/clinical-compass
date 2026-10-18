<?php

namespace App\Http\Requests;

use App\Concerns\LoginValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccessLinkPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class InvitationRegistrationRequest extends AccessLinkRequest
{
    use LoginValidationRules, PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'login' => $this->loginRules(),
            'password' => $this->passwordRules(),
            'birth_date' => $this->birthDateRules(),
            'consent' => ['accepted'],
        ];
    }

    protected function purpose(): AccessLinkPurpose
    {
        return AccessLinkPurpose::Invitation;
    }

    protected function pageRoute(): string
    {
        return 'invitation.show';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login'))) {
            $this->merge(['login' => Str::lower($this->input('login'))]);
        }
    }
}
