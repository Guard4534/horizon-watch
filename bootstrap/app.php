<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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
        // A failed validation flashes the submitted input into the session,
        // and sessions live in PostgreSQL unencrypted: the basic-auth
        // password of an environment must never be part of that. Laravel's
        // own list only covers password/password_confirmation.
        //
        // "environments" is excluded whole rather than by
        // "environments.*.basicAuthPassword" because the wizard nests one
        // password per row and Arr::except(), which is what dontFlash()
        // feeds, has no wildcard support: the wildcard key matches nothing
        // and strips nothing (checked against this version of the
        // framework). Nothing is lost by dropping the whole array — every
        // form here is an Inertia form that keeps its state client-side and
        // never reads old input back.
        $exceptions->dontFlash([
            'basicAuthPassword',
            'environments',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
