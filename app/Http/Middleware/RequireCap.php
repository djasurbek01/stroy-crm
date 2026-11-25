<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\Perm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Право по роли: cap:deals.write. Без права 403 forbidden. */
class RequireCap
{
    public function handle(Request $request, Closure $next, string $cap): Response
    {
        if (! Perm::allows($request->user(), $cap)) {
            ApiError::abort(403, 'forbidden');
        }

        return $next($request);
    }
}
