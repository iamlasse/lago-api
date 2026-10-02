<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of Api::BaseController#set_beta_header!: every /api/v2 response
 * carries `X-Lago-Endpoint-Status: beta` — including 401/403 error
 * responses, which is why this middleware is attached to the v2 route
 * group ahead of the auth middleware.
 */
class SetBetaHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Lago-Endpoint-Status', 'beta');

        return $response;
    }
}
