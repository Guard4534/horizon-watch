<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SetupToken implements ValidationRule
{
    public const int MIN_LENGTH = 16;

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = config()->string('horizon-watch.setup_token');

        if (mb_strlen($token) < self::MIN_LENGTH) {
            $fail(__('HORIZON_WATCH_SETUP_TOKEN is too short: set at least :count characters and restart the panel.', ['count' => self::MIN_LENGTH]));

            return;
        }

        if (! is_string($value) || ! hash_equals($token, $value)) {
            $fail(__('This is not the setup token of this panel.'));
        }
    }
}
