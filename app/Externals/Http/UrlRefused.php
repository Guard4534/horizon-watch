<?php

namespace App\Externals\Http;

use App\Enums\ReadingError;
use RuntimeException;

final class UrlRefused extends RuntimeException
{
    public function __construct(public readonly ReadingError $reason)
    {
        parent::__construct($reason->value);
    }
}
