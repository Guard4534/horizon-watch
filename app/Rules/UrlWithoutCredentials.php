<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UrlWithoutCredentials implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('#^[^:/?\#]*://[^/?\#]*@#', trim($value)) === 1) {
            $fail(__('Put the credentials in the basic-auth fields, not in the URL.'));
        }
    }
}
