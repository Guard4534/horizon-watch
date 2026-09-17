<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render the Blade root view; without this they would
        // need a built Vite manifest on every machine and in every lane.
        $this->withoutVite();
    }

    /**
     * A test sends several requests through one application, where a real
     * server starts each request with fresh scoped instances (php-fpm) or
     * flushes them (Octane). Without this, the scoped MonitoringRepository
     * would carry one request's user and data into the next.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app?->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
