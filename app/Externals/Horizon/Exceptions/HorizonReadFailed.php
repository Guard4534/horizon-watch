<?php

namespace App\Externals\Horizon\Exceptions;

use App\Enums\ReadingCause;
use App\Enums\ReadingError;
use RuntimeException;

final class HorizonReadFailed extends RuntimeException
{
    public function __construct(
        public readonly ReadingError $reason,
        public readonly ?ReadingCause $cause = null,
        public readonly ?int $status = null,
        public readonly ?string $location = null,
    ) {
        parent::__construct($reason->value);
    }
}
