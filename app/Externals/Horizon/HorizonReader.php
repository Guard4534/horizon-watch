<?php

namespace App\Externals\Horizon;

use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Illuminate\Container\Attributes\Bind;

#[Bind(HorizonClient::class)]
interface HorizonReader
{
    /**
     * One complete reading. Throws when stats, masters or workload fail;
     * a failed secondary call leaves its field null instead.
     *
     * @throws HorizonReadFailed
     */
    public function read(HorizonTarget $target): HorizonReading;

    /**
     * Only stats and masters, for "Test connection".
     *
     * @throws HorizonReadFailed
     */
    public function probe(HorizonTarget $target): HorizonProbe;
}
