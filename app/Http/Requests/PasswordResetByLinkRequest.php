<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Enums\AccessLinkPurpose;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordResetByLinkRequest extends AccessLinkRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }

    protected function purpose(): AccessLinkPurpose
    {
        return AccessLinkPurpose::PasswordReset;
    }

    protected function pageRoute(): string
    {
        return 'password-reset.show';
    }
}
