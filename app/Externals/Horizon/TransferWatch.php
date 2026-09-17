<?php

namespace App\Externals\Horizon;

use GuzzleHttp\TransferStats;

/**
 * What one request's transfer did, as Guzzle's callbacks saw it. Laravel
 * hands back a Response even when cURL gave up after the headers (a body
 * cut short, a timeout in the middle of it, the cap below), so the status
 * code alone cannot tell those apart from a complete answer.
 */
final class TransferWatch
{
    public bool $tooLarge = false;

    public bool $failed = false;

    public function __construct(private readonly int $maxBytes) {}

    /**
     * A truthy progress return makes cURL abort the transfer: the body is
     * never buffered past the cap. The expected size is the Content-Length,
     * so an announced oversized answer stops before its first chunk.
     *
     * @return array{progress: callable, on_stats: callable}
     */
    public function options(): array
    {
        return [
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
