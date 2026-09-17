<?php

namespace Tests\Support;

use Throwable;

final class TraceArguments
{
    public static function ofAppFrames(Throwable $exception): string
    {
        return collect($exception->getTrace())
            ->filter(fn (array $frame) => str_starts_with($frame['class'] ?? '', 'App\\'))
            ->flatMap(fn (array $frame) => $frame['args'] ?? [])
            ->filter(fn (mixed $argument) => is_string($argument))
            ->implode(' ');
    }
}
