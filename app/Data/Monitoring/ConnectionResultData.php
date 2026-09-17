<?php

namespace App\Data\Monitoring;

use App\Enums\ReadingError;
use Spatie\LaravelData\Data;

/**
 * The answer to "Test connection". Reachable carries what Horizon said;
 * unreachable carries only the reason, never a message or a body from the
 * other side.
 */
class ConnectionResultData extends Data
{
    public function __construct(
        public bool $reachable,
        public ?string $horizonStatus,
        public ?int $masterCount,
        public ?int $latencyMs,
        public ?ReadingError $error,
    ) {}

    public static function failed(ReadingError $error): self
    {
        return new self(
            reachable: false,
            horizonStatus: null,
            masterCount: null,
            latencyMs: null,
            error: $error,
        );
    }
}
