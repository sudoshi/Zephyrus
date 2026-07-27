<?php

namespace App\Http\Middleware;

use App\Services\Patient\PatientHmac;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHummingbirdPatientEnabled
{
    public function __construct(private readonly PatientHmac $hmac) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('hummingbird-patient.enabled', false)) {
            return $this->notFound();
        }

        $this->hmac->assertAvailable();

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
