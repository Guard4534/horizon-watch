<?php

namespace Tests\Fixtures\Horizon;

use App\Externals\Horizon\Dns\Resolver;

/**
 * Answers from a fixed table and remembers every name it was asked for,
 * so a test can tell whether the guard looked a host up at all.
 */
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
