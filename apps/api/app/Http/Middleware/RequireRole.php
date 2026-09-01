<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->user() === null || ! $request->user()->hasRole(...$roles)) {
            abort(403, 'This role is not authorised for the requested operation.');
        }

        return $next($request);
    }
}
