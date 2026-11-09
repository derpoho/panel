<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Google and GitHub sign-ups never knew their (random) password.
            'current_password' => $this->user()?->password_set_at === null ? ['nullable'] : $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
