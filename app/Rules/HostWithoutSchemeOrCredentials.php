<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The application host is shown to every watcher and the wizard builds the
 * suggested Horizon URLs from it, so "https://…" or "user:secret@…" in it
 * would end up in every environment URL. Any "://" and any "@" are refused.
 */
class HostWithoutSchemeOrCredentials implements ValidationRule
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

        if (str_contains($value, '://') || str_contains($value, '@')) {
            $fail(__('Enter the domain alone, without a scheme or credentials.'));
        }
    }
}
