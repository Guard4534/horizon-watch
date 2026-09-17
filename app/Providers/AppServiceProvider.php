<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Every "Test connection" makes the server open a connection somewhere,
     * so the budget is per person and shared by the two routes that do it.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('test-connection', fn (Request $request): Limit => Limit::perMinute(
            (int) config('horizon-watch.test_connection_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Self-hosted panels may run without internet, so no uncompromised()
        // lookup; ten characters matches what the interface asks for.
        Password::defaults(fn (): Password => Password::min(10)->letters()->numbers());
    }
}
