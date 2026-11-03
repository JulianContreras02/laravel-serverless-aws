<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, shares it with all log lines of the request and
 * writes one structured access log line with the route, status and duration.
 */
class RequestContext
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = $this->requestId($request);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        Log::info('HTTP request', [
            'method' => $request->method(),
            // The route pattern, not the raw path: groups requests and keeps codes out of the aggregation key.
            'route' => $request->route()?->uri() ?? 'unmatched',
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
        ]);

        return $response;
    }

    /**
     * Prefers API Gateway's request id, which also appears in its access logs,
     * so one id follows the request from the gateway to the application.
     */
    private function requestId(Request $request): string
    {
        $context = json_decode($request->server->getString('LAMBDA_REQUEST_CONTEXT'), true);

        if (is_array($context) && is_string($context['requestId'] ?? null) && $context['requestId'] !== '') {
            return $context['requestId'];
        }

        return (string) Str::uuid();
    }
}
