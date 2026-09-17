<?php

namespace App\Externals\Horizon;

use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class TransferWatch
{
    public bool $tooLarge = false;

    public bool $failed = false;

    public function __construct(private readonly int $maxBytes) {}

    /**
     * @return array{on_headers: callable, progress: callable, on_stats: callable}
     */
    public function options(): array
    {
        return [
            'on_headers' => function (ResponseInterface $response): void {
                $announced = $response->getHeaderLine('Content-Length');

                if (ctype_digit($announced) && (int) $announced > $this->maxBytes) {
                    $this->tooLarge = true;

                    throw new RuntimeException('The response is too large.');
                }
            },
            'progress' => function (int $expected, int $received): bool {
                if ($expected > $this->maxBytes || $received > $this->maxBytes) {
                    $this->tooLarge = true;
                }

                return $this->tooLarge;
            },
            'on_stats' => function (TransferStats $stats): void {
                $error = $stats->getHandlerErrorData();

                $this->failed = $error !== null && $error !== 0;
            },
        ];
    }
}
