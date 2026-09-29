<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UrlWithoutQueryOrFragment implements ValidationRule
{
    public static function carriesQueryOrFragment(string $url): bool
    {
        return str_contains($url, '?') || str_contains($url, '#');
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (self::carriesQueryOrFragment($value)) {
            $fail(__('Use the address of the Horizon dashboard, without a query string or a fragment.'));
        }
    }
}
