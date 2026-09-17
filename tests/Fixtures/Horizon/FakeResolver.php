<?php

namespace Tests\Fixtures\Horizon;

use App\Externals\Horizon\Dns\Resolver;

final class FakeResolver implements Resolver
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(private array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}
