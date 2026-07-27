<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHummingbirdPatientFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config("hummingbird-patient.features.{$feature}", false)) {
            return $this->notFound();
        }

        return $next($request);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
            ],
        ], 404);
    }
}
