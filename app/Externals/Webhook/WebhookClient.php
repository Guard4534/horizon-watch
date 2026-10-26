<?php

namespace App\Externals\Webhook;

use App\Enums\DeliveryError;
use App\Enums\ReadingError;
use App\Externals\Http\ResolvedTarget;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\TransferWatch;
use App\Externals\Http\UrlRefused;
use App\Support\Version;
use DateTimeImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class WebhookClient
{
    private const int CURLE_OPERATION_TIMEDOUT = 28;

    private const int TOO_MANY_REQUESTS = 429;

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
    public function post(#[SensitiveParameter] string $url, #[SensitiveParameter] string $secret, array $payload, #[SensitiveParameter] ?string $previousSecret = null): void
    {
        $resolved = $this->resolve($url);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $timestamp = Date::now()->getTimestamp();
        $watch = new TransferWatch($this->maxResponseBytes);
        $signatures = [Signature::SIGNATURE_HEADER => Signature::sign($secret, $timestamp, $body)];
        $retryAfter = null;

        if ($previousSecret !== null && $previousSecret !== $secret) {
            $signatures[Signature::PREVIOUS_SIGNATURE_HEADER] = Signature::sign($previousSecret, $timestamp, $body);
        }

        try {
            $response = $this->send($url, $resolved, $body, $timestamp, $signatures, $watch);
            $status = $response->status();
            $retryAfter = $status === self::TOO_MANY_REQUESTS ? self::retryAfter($response->header('Retry-After')) : null;
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
            throw new WebhookFailed($failure, $status, $retryAfter);
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

    private static function retryAfter(string $header): ?int
    {
        $header = trim($header);

        if (preg_match('/^\d{1,9}$/', $header) === 1) {
            return (int) $header;
        }

        $at = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);

        return $at === false ? null : max(0, $at->getTimestamp() - Date::now()->getTimestamp());
    }

    /**
     * @param  array<string, string>  $signatures
     */
    private function send(
        #[SensitiveParameter] string $url,
        ResolvedTarget $resolved,
        string $body,
        int $timestamp,
        #[SensitiveParameter] array $signatures,
        TransferWatch $watch,
    ): Response {
        return Http::connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withoutRedirecting()
            ->withUserAgent(Version::userAgent())
            ->withHeaders([
                'Accept-Encoding' => 'identity',
                Signature::TIMESTAMP_HEADER => (string) $timestamp,
                ...$signatures,
            ])
            ->withBody($body, 'application/json')
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
                'decode_content' => false,
                ...$watch->options(),
            ])
            ->post($url);
    }
}
