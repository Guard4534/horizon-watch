<?php

namespace App\Externals\Horizon;

use App\Models\Environment;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * The password is private and every way PHP has of printing an object
 * (var_dump, print_r, json_encode, serialize) is overridden, so a target
 * that ends up in a log, a dump or a job payload does not carry it. There
 * is no __toString on purpose. The dashboard URL is masked the same way,
 * because nothing stops someone from pasting "https://user:secret@host".
 */
final readonly class HorizonTarget implements JsonSerializable
{
    public function __construct(
        public string $dashboardUrl,
        public ?string $username,
        #[SensitiveParameter]
        private ?string $password,
    ) {}

    public static function fromEnvironment(Environment $environment): self
    {
        return new self(
            dashboardUrl: $environment->horizon_url,
            username: $environment->basic_auth_user,
            password: $environment->basic_auth_password,
        );
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * Basic auth is sent only when both halves are present.
     */
    public function hasBasicAuth(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    /**
     * The URL people copy from the browser is the dashboard; a trailing
     * slash or an "/api" pasted by mistake is dropped before the API path
     * is appended.
     */
    public function apiUrl(string $path): string
    {
        $base = rtrim($this->dashboardUrl, '/');

        if (str_ends_with($base, '/api')) {
            $base = substr($base, 0, -strlen('/api'));
        }

        return $base.'/api/'.ltrim($path, '/');
    }

    /**
     * @return array{dashboardUrl: string, username: string|null, password: string|null}
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /**
     * @return array{dashboardUrl: string, username: string|null, password: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /**
     * A target is rebuilt from its environment, never carried in a payload.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A Horizon target cannot be serialized; pass the environment instead.');
    }

    /**
     * @return array{dashboardUrl: string, username: string|null, password: string|null}
     */
    private function redacted(): array
    {
        return [
            'dashboardUrl' => (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1***@', $this->dashboardUrl),
            'username' => $this->username,
            'password' => $this->password === null ? null : '***',
        ];
    }
}
