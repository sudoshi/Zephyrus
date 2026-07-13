<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish one trusted request identifier before authentication, audit, and
 * integration middleware run. Caller-supplied identifiers are accepted only
 * when they are UUIDs; malformed or attacker-controlled values are replaced.
 */
class AssignRequestIdentity
{
    public const ATTRIBUTE = '_zephyrus_request_uuid';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function requestId(Request $request): string
    {
        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $candidate = $request->headers->get($header);
            if (is_string($candidate) && Str::isUuid($candidate)) {
                return Str::lower($candidate);
            }
        }

        return (string) Str::uuid7();
    }
}
