<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        });

        $middleware->web(append: [
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

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?RedirectResponse {
            if ($request->header('X-Inertia') === null) {
                return null;
            }

            $message = match ($exception->getStatusCode()) {
                409, 410 => __('That is no longer possible: the page was out of date.'),
                429 => __('Too many attempts. Wait a minute and try again.'),
                default => null,
            };

            if ($message === null) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(303);
        });
    })->create();
