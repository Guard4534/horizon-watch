<?php

namespace App\Rules;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Environment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The stored basic-auth password is write-only, even for admins, so it is
 * only ever sent to the address it was saved for. A blank password field
 * means "the one on file" only while the scheme, host and port stay the
 * same (and, for a test, the username too); otherwise the password has to
 * be typed again. Without this, pointing an environment — or a test — at
 * one's own host would hand over the stored secret in one request.
 *
 * Implicit, so it runs on the blank value it is about.
 */
class StoredPasswordStaysWithItsAddress implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(
        private readonly ?Environment $stored,
        private readonly mixed $horizonUrl,
        private readonly mixed $username,
        // A test pairs the stored password only with its own username;
        // saving a new username keeps re-pairing it (UpdateEnvironment), on
        // the same address and behind the credentials permission.
        private readonly bool $sameUsernameToo,
    ) {}

    /**
     * Run the validation rule.
     *
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

    /**
     * A presence check on the raw column, so nothing is decrypted to answer it.
     */
    public static function hasStoredPassword(?Environment $environment): bool
    {
        return $environment !== null
            && ($environment->getAttributes()['basic_auth_password'] ?? null) !== null;
    }
}
