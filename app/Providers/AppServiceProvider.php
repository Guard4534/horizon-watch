<?php

namespace App\Providers;

use App\Jobs\DeliverAlert;
use App\Jobs\PollEnvironmentJob;
use App\Listeners\Alerts\SendOnAlert;
use App\Monitoring\PanelHealth;
use App\Notifications\Auth\EmailChanged;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Teams\TeamInvitation;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMail();
        $this->configureQueues();
        $this->configureRateLimiting();

        AboutCommand::add('Horizon Watch', fn (): array => ['Version' => Version::current()]);
    }

    protected function configureMail(): void
    {
        if ($this->app->isProduction()) {
            config(['mail.default' => 'smtp']);
        }
    }

    protected function configureQueues(): void
    {
        Queue::route([
            PollEnvironmentJob::class => 'polls',
            DeliverAlert::class => 'notifications',
            SendOnAlert::class => 'notifications',
            TeamInvitation::class => 'notifications',
            ResetPassword::class => 'notifications',
            EmailChanged::class => 'notifications',
        ]);

        Queue::looping(fn () => rescue(fn () => app(PanelHealth::class)->recordWorkerBeat(), report: false));

        DevCommands::artisan('queue:listen --queue=notifications,polls,default --tries=1 --timeout=0', 'queue');
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('test-connection', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('horizon-watch.rate_limits.test_connection_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('test-notification', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('horizon-watch.rate_limits.test_notification_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('invitation-link', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('horizon-watch.rate_limits.invitations_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(10)->letters()->numbers());

        Markdown::withSecuredEncoding();
    }
}
