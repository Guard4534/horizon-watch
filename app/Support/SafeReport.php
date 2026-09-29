<?php

namespace App\Support;

use RuntimeException;
use Throwable;

final class SafeReport
{
    public static function of(string $context, Throwable $exception, ?string $expected = null): void
    {
        report(new RuntimeException(sprintf(
            '%s threw %s at %s:%d%s.',
            $context,
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
            $expected === null ? '' : ' instead of '.$expected,
        )));
    }
}
