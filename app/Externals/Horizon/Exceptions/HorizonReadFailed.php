<?php

namespace App\Externals\Horizon\Exceptions;

use App\Enums\ReadingError;
use RuntimeException;

/**
 * The message is the enum value and nothing else: an HTTP client exception
 * may carry the URL (with its credentials) or the response body, so it is
 * never chained as the previous exception either.
 */
final class HorizonReadFailed extends RuntimeException
{
    public function __construct(public readonly ReadingError $reason)
    {
        parent::__construct($reason->value);
    }
}
