<?php

namespace App\Externals\Horizon\Dns;

use Illuminate\Container\Attributes\Bind;

#[Bind(SystemResolver::class)]
interface Resolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
