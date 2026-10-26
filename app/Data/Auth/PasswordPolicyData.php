<?php

namespace App\Data\Auth;

use App\Support\PasswordPolicy;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;

class PasswordPolicyData extends Data
{
    public function __construct(
        public string $rules,
        public string $description,
    ) {}

    public static function current(): self
    {
        return new self(
            rules: Password::default()->toPasswordRulesString(),
            description: PasswordPolicy::description(),
        );
    }
}
