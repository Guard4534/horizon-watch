<?php

namespace App\Data\Applications;

use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;
use SensitiveParameter;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class TestConnectionData extends Data
{
    public function __construct(
        public string $horizonUrl,
        public ?string $basicAuthUser = null,
        #[SensitiveParameter]
        public ?string $basicAuthPassword = null,
    ) {}

    /**
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

    public function changesCredentialsOf(Environment $environment): bool
    {
        return StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)
            && ($this->basicAuthUser !== $environment->basic_auth_user
                || ! EnvironmentFormData::sameAddress($this->horizonUrl, $environment->horizon_url));
    }

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
