<?php

namespace App\Externals\Horizon;

use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Illuminate\Container\Attributes\Bind;

#[Bind(HorizonClient::class)]
interface HorizonReader
{
    public const int JOBS_PER_PAGE = 50;

    /**
     * @param  array<string, float|null>|null  $knownRuntimes
     *
     * @throws HorizonReadFailed
     */
    public function read(HorizonTarget $target, bool $oldestPendingJobs = false, ?array $knownRuntimes = null): HorizonReading;

    /**
     * @throws HorizonReadFailed
     */
    public function probe(HorizonTarget $target): HorizonProbe;
}
