<?php

namespace App\Data\Applications;

use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;
use SensitiveParameter;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * An address and credentials to try before (or instead of) saving them:
 * the wizard's rows, the add-environment form and the edit form. Validated
 * with EnvironmentFormData's own URL and username rules, so nothing the
 * forms would refuse is ever contacted.
 */
class TestConnectionData extends Data
{
    public function __construct(
        public string $horizonUrl,
        public ?string $basicAuthUser = null,
        // Write-only, like EnvironmentFormData's: it becomes a HorizonTarget
        // and is never put in a response.
        #[SensitiveParameter]
        public ?string $basicAuthPassword = null,
    ) {}

    /**
     * Never nested, so the keys are the bare field names.
     *
     * An unsaved address (no environment in the route) follows the create
     * rule: a username needs its password, as the wizard and the
     * add-environment form will ask when saving. On a saved environment a
     * blank password stands for the stored one only on its own address and
     * with its own username (StoredPasswordStaysWithItsAddress).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $environment = self::environmentUnderTest();

        return [
            'horizonUrl' => EnvironmentFormData::horizonUrlRules(),
            'basicAuthUser' => EnvironmentFormData::basicAuthUserRules('basicAuthPassword'),
            'basicAuthPassword' => [
                'nullable',
                'string',
                ...($environment === null
                    ? ['required_with:basicAuthUser']
                    : [new StoredPasswordStaysWithItsAddress(
                        stored: $environment,
                        horizonUrl: $context->payload['horizonUrl'] ?? null,
                        username: $context->payload['basicAuthUser'] ?? null,
                        sameUsernameToo: true,
                    )]),
            ],
        ];
    }

    /**
     * Whether testing this body against the environment touches its stored
     * credential: another username, or another scheme, host or port, for an
     * environment that has a password on file. Such a test needs the
     * credentials permission, as saving the same change would.
     */
    public function changesCredentialsOf(Environment $environment): bool
    {
        return StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)
            && ($this->basicAuthUser !== $environment->basic_auth_user
                || ! EnvironmentFormData::sameAddress($this->horizonUrl, $environment->horizon_url));
    }

    /**
     * The target to probe. Unsaved addresses pass no environment and use
     * only what was typed. On the edit form a blank password means "the one
     * on file", but only for the address and username it was saved for —
     * validation refuses the other cases, and this checks again rather than
     * trusting that it ran. No username means no credential at all.
     */
    public function target(?Environment $stored = null): HorizonTarget
    {
        return new HorizonTarget(
            dashboardUrl: $this->horizonUrl,
            username: $this->basicAuthUser,
            password: $this->password($stored),
        );
    }

    private function password(?Environment $stored): ?string
    {
        if ($this->basicAuthUser === null) {
            return null;
        }

        if (filled($this->basicAuthPassword)) {
            return $this->basicAuthPassword;
        }

        if ($stored === null
            || $this->basicAuthUser !== $stored->basic_auth_user
            || ! EnvironmentFormData::sameAddress($this->horizonUrl, $stored->horizon_url)) {
            return null;
        }

        return $stored->basic_auth_password;
    }

    private static function environmentUnderTest(): ?Environment
    {
        $environment = request()->route()?->parameter('environment');

        return $environment instanceof Environment ? $environment : null;
    }
}
