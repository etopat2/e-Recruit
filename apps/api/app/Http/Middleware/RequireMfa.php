<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMfa
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ($user->is_privileged && $user->mfa_confirmed_at === null)) {
            abort(403, 'Multi-factor authentication is required for this account.');
        }

        return $next($request);
    }
}
