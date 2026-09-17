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
        //
        // "horizonUrl" goes too: a URL is refused when it carries
        // "user:password@" (UrlWithoutCredentials), and flashing it back
        // would store exactly that credential.
        $exceptions->dontFlash([
            'basicAuthPassword',
            'environments',
            'horizonUrl',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // An abort() is not an Inertia response: the visit gets a body it
        // cannot turn into a page and the client shows its own "unexpected
        // response" modal, with the status code in it and nothing a user
        // could act on. These three are the ones an ordinary click reaches,
        // and all three mean the same thing to the person clicking — the
        // page is out of date, look again:
        //
        //   409  Resend or Revoke on an invitation that someone else (or
        //        another tab) already answered; the register form losing
        //        the race against a second submission.
        //   410  an invitation that closed while its page was open.
        //   429  a throttle: the invitation routes (register, accept,
        //        decline and Resend are throttle:6,1; Revoke is not),
        //        and by the same path setup, login and the password form.
        //        Every limiter in the app is per minute, which is what the
        //        message promises.
        //
        // So they come back as a redirect carrying the toast the rest of
        // the app already flashes, which also re-renders the page against
        // the state that caused the refusal. Gated on the Inertia header:
        // a non-Inertia caller (and every test that asserts the status)
        // still gets the bare code. 303 because the aborts happen on POST
        // and DELETE, which a browser must not repeat against the
        // redirect target.
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
