<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiV1Errors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outer edge of /api/v1. It runs before the framework's CORS handling and removes it for this API: a token
 * must never be used from a browser page, so no origin is ever allowed and a preflight is refused. Every
 * response of the API, whatever produced it, carries the same security headers.
 */
class ApiV1Boundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }
        if ($request->isMethod('OPTIONS')) {
            return ApiV1Errors::error(405, 'method_not_allowed', 'This endpoint is read-only: use GET.', [], ['Allow' => 'GET, HEAD']);
        }
        $response = $next($request);
        foreach (array_keys($response->headers->all()) as $name) {
            if (str_starts_with($name, 'access-control-')) {
                $response->headers->remove($name);
            }
        }

        return ApiV1Errors::decorate($response);
    }
}
