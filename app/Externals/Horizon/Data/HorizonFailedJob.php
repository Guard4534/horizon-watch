<?php

namespace App\Externals\Horizon\Data;

use Carbon\CarbonImmutable;

/**
 * The job payload never leaves the client: it may hold anything the
 * application put in it.
 */
final readonly class HorizonFailedJob
{
    /**
     * @param  string  $exception  first line only, at most 200 characters
     */
    public function __construct(
        public string $name,
        public string $queue,
        public string $exception,
        public int $attempts,
        public CarbonImmutable $failedAt,
    ) {}
}
