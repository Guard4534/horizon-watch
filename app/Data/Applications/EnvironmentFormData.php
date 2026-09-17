<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;
use App\Rules\UrlWithoutCredentials;
use App\Rules\UrlWithoutQueryOrFragment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class EnvironmentFormData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public ?string $basicAuthUser = null,
        public ?string $basicAuthPassword = null,
        public int $pollIntervalSeconds = 15,
        public bool $pollingEnabled = true,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', ...self::uniqueNameRules()],
            'color' => ['required', Rule::enum(EnvironmentColor::class)],
            'horizonUrl' => self::horizonUrlRules(),
            'basicAuthUser' => self::basicAuthUserRules(self::key($context, 'basicAuthPassword')),
            'basicAuthPassword' => ['nullable', 'string', ...self::passwordRequiredWithUsernameRules($context)],
            'pollIntervalSeconds' => ['integer', 'between:15,300'],
            'pollingEnabled' => ['boolean'],
        ];
    }

    /**
     * @return array<int, string|UrlWithoutCredentials|UrlWithoutQueryOrFragment>
     */
    public static function horizonUrlRules(): array
    {
        return ['required', 'url:http,https', 'max:255', new UrlWithoutCredentials, new UrlWithoutQueryOrFragment];
    }

    /**
     * @return array<int, string>
     */
    public static function basicAuthUserRules(string $passwordKey): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            'regex:/^[^\s:]+$/',
            'required_with:'.$passwordKey,
        ];
    }

    private static function key(ValidationContext $context, string $field): string
    {
        return $context->path->isRoot()
            ? $field
            : $context->path->property($field)->get();
    }

    /**
     * @return array<int, string|StoredPasswordStaysWithItsAddress>
     */
    private static function passwordRequiredWithUsernameRules(ValidationContext $context): array
    {
        $environment = self::environmentBeingUpdated();

        if ($environment === null) {
            return ['required_with:'.self::key($context, 'basicAuthUser')];
        }

        return [new StoredPasswordStaysWithItsAddress(
            stored: $environment,
            horizonUrl: $context->payload['horizonUrl'] ?? null,
            username: $context->payload['basicAuthUser'] ?? null,
            sameUsernameToo: false,
        )];
    }

    public static function sameAddress(string $first, string $second): bool
    {
        $origin = function (string $url): ?string {
            $parts = parse_url(trim($url));

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            $scheme = strtolower($parts['scheme']);
            $port = $parts['port'] ?? match ($scheme) {
                'http' => 80,
                'https' => 443,
                default => null,
            };

            return $scheme.'://'.strtolower($parts['host']).':'.$port;
        };

        $firstOrigin = $origin($first);

        return $firstOrigin !== null && $firstOrigin === $origin($second);
    }

    public static function withoutUserinfo(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#]*@#i', '$1', $url);
    }

    /**
     * @return array<int, Unique>
     */
    private static function uniqueNameRules(): array
    {
        $application = self::parentApplication();

        if ($application === null) {
            return [];
        }

        $environment = self::environmentBeingUpdated();
        $rule = Rule::unique('environments', 'name')->where('application_id', $application->id);

        return [$environment === null ? $rule : $rule->ignore($environment)];
    }

    private static function environmentBeingUpdated(): ?Environment
    {
        $environment = request()->route()?->parameter('environment');

        return $environment instanceof Environment ? $environment : null;
    }

    private static function parentApplication(): ?Application
    {
        $environment = self::environmentBeingUpdated();

        if ($environment !== null) {
            return $environment->application;
        }

        $application = request()->route()?->parameter('application');

        return $application instanceof Application ? $application : null;
    }

    public function hasNewPassword(): bool
    {
        return filled($this->basicAuthPassword);
    }

    public function changesCredentialsOf(Environment $environment): bool
    {
        return $this->basicAuthUser !== $environment->basic_auth_user
            || $this->hasNewPassword()
            || (StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)
                && ! self::sameAddress($this->horizonUrl, $environment->horizon_url));
    }
}
