<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-tenant bearer token for the management API. Fails closed: when no
 * token is configured, every request is rejected.
 */
class AuthenticateApiToken
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
