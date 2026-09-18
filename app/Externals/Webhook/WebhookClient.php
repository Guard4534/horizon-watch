<?php

namespace App\Externals\Webhook;

use App\Enums\DeliveryError;
use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\ResolvedTarget;
use App\Externals\Horizon\SafeUrlGuard;
use GuzzleHttp\TransferStats;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
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
        $transfer = new WebhookTransfer;

        try {
            $response = $this->send($url, $resolved, $body, $timestamp, Signature::sign($secret, $timestamp, $body), $transfer);
            $status = $response->status();
        } catch (Throwable) {
            $status = $transfer->tooLarge ? $transfer->status : null;
        }

        if ($status === null) {
            throw new WebhookFailed($transfer->errno === self::CURLE_OPERATION_TIMEDOUT ? DeliveryError::Timeout : DeliveryError::Unreachable);
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
        } catch (HorizonReadFailed $exception) {
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
        WebhookTransfer $transfer,
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
                'on_headers' => function (ResponseInterface $response) use ($transfer): void {
                    $transfer->status = $response->getStatusCode();
                    $announced = $response->getHeaderLine('Content-Length');

                    if (ctype_digit($announced) && (int) $announced > $this->maxResponseBytes) {
                        $transfer->tooLarge = true;
                    }
                },
                'progress' => function (int $expected, int $received) use ($transfer): bool {
                    if ($expected > $this->maxResponseBytes || $received > $this->maxResponseBytes) {
                        $transfer->tooLarge = true;
                    }

                    return $transfer->tooLarge;
                },
                'on_stats' => function (TransferStats $stats) use ($transfer): void {
                    $error = $stats->getHandlerErrorData();
                    $transfer->errno = is_int($error) ? $error : null;
                },
            ])
            ->post($url);
    }
}
