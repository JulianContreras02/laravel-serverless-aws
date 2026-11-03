<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-tenant bearer token for the management API. Fails closed: when no
 * token is configured, every request is rejected.
 *
 * Implementing AuthenticatesRequests puts this middleware before route model
 * binding in Laravel's priority list. Otherwise an unknown code answers 404
 * before authentication runs, and anyone could probe which codes exist.
 */
class AuthenticateApiToken implements AuthenticatesRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = Config::get('shortlinks.api_token');
        $given = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || $given === null || ! $this->matches($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }

    /**
     * Constant-time comparison of fixed-length digests, so not even the token length leaks.
     */
    private function matches(string $expected, string $given): bool
    {
        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }
}
