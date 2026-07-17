<?php

namespace Webkul\Purchase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's own `signed` middleware validates the hash but accepts a
 * signature generated without an expiration (URL::signedRoute(), a
 * permanent link) just as readily as one generated with
 * URL::temporarySignedRoute() — hasValidSignature() only checks
 * `expires` when the query string actually carries one. The RFQ
 * response capability is meant to always expire (#138 audit, PR 1
 * review round 2), so a signature with no `expires` at all must be
 * rejected here, on top of the `signed` middleware's own hash check.
 */
class RequireSignatureExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->query('expires'), 403);

        return $next($request);
    }
}
