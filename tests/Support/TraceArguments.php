<?php

namespace Tests\Support;

use Throwable;

final class TraceArguments
{
    /**
     * Every string argument of the application frames in the trace, whole:
     * the printed trace cuts strings at 15 characters and would hide a leak.
     * Only meaningful with zend.exception_ignore_args off.
     */
    public static function ofAppFrames(Throwable $exception): string
    {
        return collect($exception->getTrace())
            ->filter(fn (array $frame) => str_starts_with($frame['class'] ?? '', 'App\\'))
            ->flatMap(fn (array $frame) => $frame['args'] ?? [])
            ->filter(fn (mixed $argument) => is_string($argument))
            ->implode(' ');
    }
}
