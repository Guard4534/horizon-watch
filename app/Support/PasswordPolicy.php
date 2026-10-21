<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 10;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->numbers();
    }

    public static function description(): string
    {
        return __('At least :min characters, with letters and numbers', ['min' => self::MIN_LENGTH]);
    }
}
