<?php

namespace App\Data\Applications;

use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use SensitiveParameter;
use Spatie\LaravelData\Data;

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
     * Never nested, so the password's key is the bare field name.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'horizonUrl' => EnvironmentFormData::horizonUrlRules(),
            'basicAuthUser' => EnvironmentFormData::basicAuthUserRules('basicAuthPassword'),
            'basicAuthPassword' => ['nullable', 'string'],
        ];
    }

    /**
     * The target to probe. On the edit form a blank password means "the one
     * on file", exactly as UpdateEnvironment reads it when saving: the
     * stored password is used with whatever username was sent, and no
     * username means no credential at all. Unsaved addresses pass no
     * environment and use only what was typed.
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

        return $stored?->basic_auth_password;
    }
}
