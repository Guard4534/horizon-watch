<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The client appends "/api/..." to the dashboard URL: after a fragment every
 * call would go to the dashboard page itself, and after a query string the
 * API path would end up inside the query. An empty "?" or "#" counts too.
 */
class UrlWithoutQueryOrFragment implements ValidationRule
{
    /**
     * Run the validation rule.
     *
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
