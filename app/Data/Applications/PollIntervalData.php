<?php

namespace App\Data\Applications;

use App\Actions\Monitoring\DispatchDuePolls;
use Spatie\LaravelData\Data;

class PollIntervalData extends Data
{
    public function __construct(
        public int $default,
        public int $min,
        public int $max,
        public int $step,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            default: config()->integer('horizon-watch.readings.poll_interval_seconds.default'),
            min: config()->integer('horizon-watch.readings.poll_interval_seconds.min'),
            max: config()->integer('horizon-watch.readings.poll_interval_seconds.max'),
            step: DispatchDuePolls::TICK_SECONDS,
        );
    }
}
