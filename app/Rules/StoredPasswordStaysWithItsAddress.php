<?php

namespace App\Rules;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Environment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class StoredPasswordStaysWithItsAddress implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(
        private readonly ?Environment $stored,
        private readonly mixed $horizonUrl,
        private readonly mixed $username,
        private readonly bool $sameUsernameToo,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->stored === null
            || filled($value)
            || blank($this->username)
            || ! self::hasStoredPassword($this->stored)) {
            return;
        }

        $sameAddress = is_string($this->horizonUrl)
            && EnvironmentFormData::sameAddress($this->horizonUrl, $this->stored->horizon_url);
        $sameUsername = $this->username === $this->stored->basic_auth_user;

        if (! $sameAddress || ($this->sameUsernameToo && ! $sameUsername)) {
            $fail(__('Type the password again: the stored one is only used with the address and username it was saved for.'));
        }
    }

    public static function hasStoredPassword(?Environment $environment): bool
    {
        return $environment !== null
            && ($environment->getAttributes()['basic_auth_password'] ?? null) !== null;
    }
}
