<?php

namespace App\Externals\Horizon\Exceptions;

use App\Enums\ReadingError;
use RuntimeException;

final class HorizonReadFailed extends RuntimeException
{
    public function __construct(public readonly ReadingError $reason)
    {
        parent::__construct($reason->value);
    }
}
