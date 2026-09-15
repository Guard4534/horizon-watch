<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->locale($request)->value);

        return $next($request);
    }

    protected function locale(Request $request): Locale
    {
        $supported = array_column(Locale::cases(), 'value');

        $userLocale = $request->user()?->locale;

        return $userLocale
            ?? Locale::tryFrom((string) $request->session()->get('locale'))
            ?? Locale::tryFrom((string) $request->getPreferredLanguage($supported))
            ?? Locale::tryFrom((string) config('app.locale'))
            ?? Locale::En;
    }
}
