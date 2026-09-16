<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use Spatie\LaravelData\Data;

/**
 * Read-only view of an environment for the edit form's pre-filled values.
 * Deliberately has no password property at all — not even a null one —
 * so the basic-auth password cannot leak into this prop by construction,
 * not just by discipline. This is what EnvironmentFormPageData's
 * "environment" carries, and the page reports only whether a password is
 * on file, through that Data's "hasPassword" boolean.
 */
class EnvironmentSummaryData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public ?string $basicAuthUser,
        public int $pollIntervalSeconds,
    ) {}
}
