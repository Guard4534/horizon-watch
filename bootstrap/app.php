<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ManifestController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::get('/health', HealthController::class)->name('health');
            Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        $proxies = trim((string) env('TRUSTED_PROXIES', 'private'));

        $middleware->trustProxies(at: match ($proxies) {
            '*' => '*',
            'private' => [
                '127.0.0.0/8',
                '::1/128',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                'fc00::/7',
            ],
            default => array_values(array_filter(array_map(trim(...), explode(',', $proxies)))),
        }, headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->redirectUsersTo(fn () => route('home'));

        $middleware->web(append: [
            AuthenticateSession::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'basicAuthPassword',
            'environments',
            'horizonUrl',
            'application',
            'host',
            'webhookUrl',
            'webhookSecret',
            'recipients',
        ]);

        $rendersJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendersJson);

        $exceptions->render(function (DecryptException $exception, Request $request): ?RedirectResponse {
            if (! $request->routeIs('two-factor.login.store')) {
                return null;
            }

            return redirect()->route('two-factor.login')->withErrors([
                $request->filled('recovery_code') ? 'recovery_code' : 'code' => __('The two-factor secret of this account can no longer be read: ask an administrator to turn two-factor authentication off.'),
            ]);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?RedirectResponse {
            if ($request->header('X-Inertia') === null) {
                return null;
            }

            $message = match ($exception->getStatusCode()) {
                409, 410 => __('That is no longer possible: the page was out of date.'),
                419 => __('The page had expired. Try again.'),
                429 => __('Too many attempts. Wait a minute and try again.'),
                default => null,
            };

            if ($message === null) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(303);
        });

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) use ($rendersJson): ExceptionResponse|RedirectResponse|null {
            $status = $response->statusCode();

            if ($rendersJson($response->request) || ! in_array($status, [403, 404, 419, 500, 503], true)) {
                return null;
            }

            if ($status === 500 && config()->boolean('app.debug')) {
                return null;
            }

            $request = $response->request;

            if ($request->header('X-Inertia') !== null && ! $request->isMethodSafe() && $request->hasSession()) {
                Inertia::flash('toast', ['type' => 'error', 'message' => match ($status) {
                    403 => __('You are not allowed to do that.'),
                    404 => __('That is no longer possible: the page was out of date.'),
                    503 => __('Horizon Watch is being updated and will be back in a few minutes.'),
                    default => __('Something went wrong. Try again in a moment.'),
                }]);

                return back(303);
            }

            $user = Auth::hasUser() ? Auth::user() : null;

            App::setLocale(SetLocale::resolve($request, $user instanceof User ? $user : null)->value);

            Inertia::flushShared();

            return $response->render('Error', ['status' => $status, 'locale' => App::getLocale(), 'name' => config('app.name')]);
        });
    })->create();
