<?php

namespace App\Externals\Horizon;

use GuzzleHttp\TransferStats;

final class TransferWatch
{
    public bool $tooLarge = false;

    public bool $failed = false;

    public function __construct(private readonly int $maxBytes) {}

    /**
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
