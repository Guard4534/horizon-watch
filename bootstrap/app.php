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
