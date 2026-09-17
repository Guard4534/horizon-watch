<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class HostWithoutSchemeOrCredentials implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_contains($value, '://') || str_contains($value, '@')) {
            $fail(__('Enter the domain alone, without a scheme or credentials.'));
        }
    }
}
