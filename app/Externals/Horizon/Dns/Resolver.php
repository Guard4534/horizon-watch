<?php

namespace App\Externals\Horizon\Dns;

use Illuminate\Container\Attributes\Bind;

#[Bind(SystemResolver::class)]
interface Resolver
{
    /**
     * Every IPv4 and IPv6 address the name points to; an empty list when it
     * does not resolve. An address literal comes back as itself.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
