<?php

namespace App\Data\Monitoring;

use App\Enums\ReadingCause;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Spatie\LaravelData\Data;

class ReadingProblemData extends Data
{
    public function __construct(
        public ReadingCause $cause,
        public ?int $status,
        public ?string $location,
    ) {}

    public static function fromFailure(HorizonReadFailed $failure): ?self
    {
        return $failure->cause === null ? null : new self($failure->cause, $failure->status, $failure->location);
    }

    /**
     * @param  array<array-key, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        $cause = is_string($stored['cause'] ?? null) ? ReadingCause::tryFrom($stored['cause']) : null;

        if ($cause === null) {
            return null;
        }

        return new self(
            cause: $cause,
            status: is_int($stored['status'] ?? null) ? $stored['status'] : null,
            location: is_string($stored['location'] ?? null) ? $stored['location'] : null,
        );
    }
}
