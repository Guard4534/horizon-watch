<?php

namespace App\Rules;

use App\Enums\ReadingError;
use App\Externals\Http\Dns\Resolver;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\UrlRefused;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class OutboundUrl implements ValidationRule
{
    public static function carriesCredentials(string $url): bool
    {
        return preg_match('#^[^:/?\#]*://[^/?\#]*@#', trim($url)) === 1;
    }

    public static function carriesQueryOrFragment(string $url): bool
    {
        return str_contains($url, '?') || str_contains($url, '#');
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $url = trim($value);

        if (preg_match('#^https?://#i', $url) !== 1) {
            return;
        }

        if (self::carriesCredentials($url)) {
            $fail($this->credentialsMessage());

            return;
        }

        if (self::carriesQueryOrFragment($url)) {
            $fail($this->queryMessage());

            return;
        }

        if ($this->blocked($url)) {
            $fail($this->blockedMessage());
        }
    }

    protected function credentialsMessage(): string
    {
        return __('Put the credentials in the basic-auth fields, not in the Horizon URL.');
    }

    protected function queryMessage(): string
    {
        return __('The Horizon URL cannot have a query string or a fragment.');
    }

    protected function blockedMessage(): string
    {
        return __('This address points to a network the panel does not call.');
    }

    private function blocked(string $url): bool
    {
        $guard = new SafeUrlGuard(
            new class implements Resolver
            {
                public function resolve(string $host): array
                {
                    return filter_var($host, FILTER_VALIDATE_IP) === false ? [] : [$host];
                }
            },
            config()->boolean('horizon-watch.block_private_networks', false),
        );

        try {
            $guard->check($url);
        } catch (UrlRefused $exception) {
            return $exception->reason === ReadingError::Blocked;
        }

        return false;
    }
}
