<?php

namespace App\Rules;

use App\Enums\ReadingError;
use App\Externals\Horizon\Dns\Resolver;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\SafeUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class WebhookUrl implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $url = trim($value);

        if (preg_match('#^[^:/?\#]*://[^/?\#]*@#', $url) === 1) {
            $fail($this->credentialsMessage());

            return;
        }

        if (str_contains($url, '?') || str_contains($url, '#')) {
            $fail($this->queryMessage());

            return;
        }

        if ($this->blocked($url)) {
            $fail($this->blockedMessage());
        }
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
            (bool) config('horizon-watch.block_private_networks', false),
        );

        try {
            $guard->check($url);
        } catch (HorizonReadFailed $exception) {
            return $exception->reason === ReadingError::Blocked;
        }

        return false;
    }

    private function credentialsMessage(): string
    {
        return __('The webhook address cannot hold credentials: put a token in its path.');
    }

    private function queryMessage(): string
    {
        return __('The webhook address cannot have a query string or a fragment: put a token in its path.');
    }

    private function blockedMessage(): string
    {
        return __('This webhook address points to a network the panel does not call.');
    }
}
