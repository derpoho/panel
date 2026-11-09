<?php

namespace App\Rules;

use App\Support\Auth\DisposableEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(DisposableEmail::class)->is($value)) {
            $fail('Use an email address you keep. Temporary inboxes can’t be used for a ServerOS account.');
        }
    }
}
