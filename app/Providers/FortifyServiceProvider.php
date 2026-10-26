<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Data\Auth\PasswordPolicyData;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn () => $this->app->make(
            SuccessfulPasswordResetLinkRequestResponse::class,
            ['status' => Password::RESET_LINK_SENT],
        ));
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordPolicy' => PasswordPolicyData::current(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    private function configureRateLimiting(): void
    {
        Event::listen(Failed::class, fn () => request()->attributes->set('login_failed', true));

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(config()->integer('horizon-watch.rate_limits.two_factor_per_minute'))->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate($request->string(Fortify::username())->lower()->value());

            return [
                Limit::perMinute(config()->integer('horizon-watch.rate_limits.login_per_minute'))->by($email.'|'.$request->ip()),
                Limit::perHour(config()->integer('horizon-watch.rate_limits.login_per_account_per_hour'))->by('account|'.$email)
                    ->after(fn (): bool => $request->attributes->getBoolean('login_failed')),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = Str::transliterate($request->string(Fortify::email())->lower()->value());

            return [
                Limit::perMinute(config()->integer('horizon-watch.rate_limits.password_reset_per_minute'))->by($request->ip()),
                Limit::perHour(config()->integer('horizon-watch.rate_limits.password_reset_per_account_per_hour'))->by('account|'.$email),
            ];
        });

        RateLimiter::for('current-password', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('horizon-watch.rate_limits.current_password_per_minute'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
