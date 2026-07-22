<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Patient communication responses are NO_CACHE on every path, including
 * feature/capability denials and request-validation failures rendered before a
 * controller action runs.
 */
class ProtectPatientCommunicationResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            report($exception);
            $response = app(ExceptionHandler::class)->render($request, $exception);
        }

        return self::protect($response);
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
