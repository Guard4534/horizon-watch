<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->locale($request)->value);

        return $next($request);
    }

    protected function locale(Request $request): Locale
    {
        return self::resolve($request, $request->user());
    }

    public static function resolve(Request $request, ?User $user): Locale
    {
        return $user->locale
            ?? ($request->hasSession() ? Locale::tryFrom((string) $request->session()->get('locale')) : null)
            ?? self::preferred($request);
    }

    public static function preferred(Request $request): Locale
    {
        $default = Locale::tryFrom((string) config('app.locale')) ?? Locale::En;

        $supported = array_values(array_unique([$default->value, ...array_column(Locale::cases(), 'value')]));

        return Locale::tryFrom((string) $request->getPreferredLanguage($supported)) ?? $default;
    }
}
