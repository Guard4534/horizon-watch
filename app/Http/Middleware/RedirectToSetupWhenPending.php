<?php

namespace App\Http\Middleware;

use App\Support\SetupStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetupWhenPending
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! SetupStatus::isComplete()) {
            return redirect()->route('setup.create');
        }

        return $next($request);
    }
}
