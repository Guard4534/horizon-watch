<?php

namespace App\Data\Monitoring;

use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use Spatie\LaravelData\Data;

class ConnectionResultData extends Data
{
    public function __construct(
        public bool $reachable,
        public ?HorizonStatus $horizonStatus,
        public ?int $masterCount,
        public ?int $latencyMs,
        public ?ReadingError $error,
        public ?ReadingProblemData $problem = null,
        public ?string $suggestedUrl = null,
        public bool $credentialsInClear = false,
    ) {}

    public static function failed(ReadingError $error, ?ReadingProblemData $problem = null, ?string $suggestedUrl = null, bool $credentialsInClear = false): self
    {
        return new self(
            reachable: false,
            horizonStatus: null,
            masterCount: null,
            latencyMs: null,
            error: $error,
            problem: $problem,
            suggestedUrl: $suggestedUrl,
            credentialsInClear: $credentialsInClear,
        );
    }
}
