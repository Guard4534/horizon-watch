<?php

namespace App\Externals\Heartbeat;

use App\Enums\DeliveryError;
use App\Enums\ReadingError;
use App\Externals\Http\ResolvedTarget;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\TransferWatch;
use App\Externals\Http\UrlRefused;
use App\Support\Version;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class HeartbeatClient
{
    private const int CURLE_OPERATION_TIMEDOUT = 28;

    private const int MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.health.heartbeat_timeout_seconds', 5)]
        private int|float $timeoutSeconds = 5,
    ) {}

    public function ping(#[SensitiveParameter] string $url): ?DeliveryError
    {
        try {
            $resolved = $this->guard->check($url);
        } catch (UrlRefused $exception) {
            return $exception->reason === ReadingError::Blocked ? DeliveryError::Blocked : DeliveryError::Unreachable;
        } catch (Throwable) {
            return DeliveryError::Blocked;
        }

        $watch = new TransferWatch(self::MAX_RESPONSE_BYTES);

        try {
            $status = $this->send($url, $resolved, $watch);
        } catch (Throwable) {
            $status = $watch->tooLarge ? $watch->status : null;
        }

        return match (true) {
            $status === null => $watch->errno === self::CURLE_OPERATION_TIMEDOUT ? DeliveryError::Timeout : DeliveryError::Unreachable,
            $status >= 200 && $status < 300 => null,
            $status >= 300 && $status < 400 => DeliveryError::Redirect,
            $status >= 400 && $status < 500 => DeliveryError::ClientError,
            default => DeliveryError::ServerError,
        };
    }

    private function send(#[SensitiveParameter] string $url, ResolvedTarget $resolved, TransferWatch $watch): int
    {
        return Http::connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withoutRedirecting()
            ->withUserAgent(Version::userAgent())
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
                'decode_content' => false,
                ...$watch->options(),
            ])
            ->get($url)
            ->status();
    }
}
