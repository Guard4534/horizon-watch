<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UrlWithoutQueryOrFragment implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_contains($value, '?') || str_contains($value, '#')) {
            $fail(__('Use the address of the Horizon dashboard, without a query string or a fragment.'));
        }
    }
}
