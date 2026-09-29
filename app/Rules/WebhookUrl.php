<?php

namespace App\Rules;

use App\Enums\ReadingError;
use App\Externals\Http\Dns\Resolver;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\UrlRefused;
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

        if (UrlWithoutCredentials::carriesCredentials($url)) {
            $fail($this->credentialsMessage());

            return;
        }

        if (UrlWithoutQueryOrFragment::carriesQueryOrFragment($url)) {
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
            config()->boolean('horizon-watch.block_private_networks', false),
        );

        try {
            $guard->check($url);
        } catch (UrlRefused $exception) {
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
