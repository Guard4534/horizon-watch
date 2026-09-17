<?php

namespace App\Externals\Horizon;

use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Illuminate\Container\Attributes\Bind;

#[Bind(HorizonClient::class)]
interface HorizonReader
{
    /**
     * @throws HorizonReadFailed
     */
    public function read(HorizonTarget $target): HorizonReading;

    /**
     * @throws HorizonReadFailed
     */
    public function probe(HorizonTarget $target): HorizonProbe;
}
