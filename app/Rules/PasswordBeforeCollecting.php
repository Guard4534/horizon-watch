<?php

namespace App\Rules;

use App\Models\Environment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PasswordBeforeCollecting implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(
        private readonly ?Environment $stored,
        private readonly mixed $username,
        private readonly mixed $pollingEnabled,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->stored === null
            || filled($value)
            || blank($this->username)
            || StoredPasswordStaysWithItsAddress::hasStoredPassword($this->stored)
            || ($this->stored->polling_enabled && $this->username === $this->stored->basic_auth_user)
            || filter_var($this->pollingEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            return;
        }

        $fail(__('Enter the basic-auth password before turning collection on.'));
    }
}
