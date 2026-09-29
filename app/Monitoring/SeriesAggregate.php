<?php

namespace App\Monitoring;

enum SeriesAggregate
{
    case Throughput;

    case MaxWait;

    public function sql(): string
    {
        return match ($this) {
            self::Throughput => 'avg(s.jobs_per_minute)',
            self::MaxWait => 'max(s.max_wait_seconds)',
        };
    }
}
