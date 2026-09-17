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
    public function register(): void {}

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('test-connection', fn (Request $request): Limit => Limit::perMinute(
            (int) config('horizon-watch.test_connection_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('test-notification', fn (Request $request): Limit => Limit::perMinute(
            (int) config('horizon-watch.test_notification_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(10)->letters()->numbers());
    }
}
