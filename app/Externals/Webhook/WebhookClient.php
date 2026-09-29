<?php

namespace App\Externals\Webhook;

use App\Enums\DeliveryError;
use App\Enums\ReadingError;
use App\Externals\Http\ResolvedTarget;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\TransferWatch;
use App\Externals\Http\UrlRefused;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class WebhookClient
{
    private const int CURLE_OPERATION_TIMEDOUT = 28;

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.webhook_timeout_seconds', 5)]
        private int|float $timeoutSeconds = 5,
        #[Config('horizon-watch.webhook_response_bytes', 65536)]
        private int $maxResponseBytes = 65536,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookFailed
     */
    public function post(#[SensitiveParameter] string $url, #[SensitiveParameter] string $secret, array $payload): void
    {
        $resolved = $this->resolve($url);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $timestamp = Date::now()->getTimestamp();
        $watch = new TransferWatch($this->maxResponseBytes);

        try {
            $response = $this->send($url, $resolved, $body, $timestamp, Signature::sign($secret, $timestamp, $body), $watch);
            $status = $response->status();
        } catch (Throwable) {
            $status = $watch->tooLarge ? $watch->status : null;
        }

        if ($status === null) {
            throw new WebhookFailed($watch->errno === self::CURLE_OPERATION_TIMEDOUT ? DeliveryError::Timeout : DeliveryError::Unreachable);
        }

        $failure = match (true) {
            $status >= 200 && $status < 300 => null,
            $status >= 300 && $status < 400 => DeliveryError::Redirect,
            $status >= 400 && $status < 500 => DeliveryError::ClientError,
            default => DeliveryError::ServerError,
        };

        if ($failure !== null) {
            throw new WebhookFailed($failure);
        }
    }

    /**
     * @throws WebhookFailed
     */
    private function resolve(#[SensitiveParameter] string $url): ResolvedTarget
    {
        try {
            return $this->guard->check($url);
        } catch (UrlRefused $exception) {
            throw new WebhookFailed($exception->reason === ReadingError::Blocked ? DeliveryError::Blocked : DeliveryError::Unreachable);
        } catch (Throwable) {
            throw new WebhookFailed(DeliveryError::Blocked);
        }
    }

    private function send(
        #[SensitiveParameter] string $url,
        ResolvedTarget $resolved,
        string $body,
        int $timestamp,
        #[SensitiveParameter] string $signature,
        TransferWatch $watch,
    ): Response {
        return Http::connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withoutRedirecting()
            ->withUserAgent('HorizonWatch')
            ->withHeaders([
                Signature::TIMESTAMP_HEADER => (string) $timestamp,
                Signature::SIGNATURE_HEADER => $signature,
            ])
            ->withBody($body, 'application/json')
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
                ...$watch->options(),
            ])
            ->post($url);
    }
}
