<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Horizon URL is shown to everyone who watches the environment, viewers
 * included, and becomes the "Open Horizon" link, while the basic-auth
 * password is stored encrypted and never shown. So "https://user:secret@host"
 * is refused rather than stored: the credential belongs in its own fields.
 *
 * The authority is everything between "://" and the first "/", "?" or "#".
 * Any "@" in there is refused, a bare one included, and a backslash does not
 * end the authority here: browsers read "\" as "/" but PHP and Guzzle do
 * not, so "https://a\b@host" still carries userinfo for the client.
 */
class UrlWithoutCredentials implements ValidationRule
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

        if (preg_match('#^[^:/?\#]*://[^/?\#]*@#', trim($value)) === 1) {
            $fail(__('Put the credentials in the basic-auth fields, not in the URL.'));
        }
    }
}
