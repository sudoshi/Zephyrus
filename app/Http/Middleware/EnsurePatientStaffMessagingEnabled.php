<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientStaffMessagingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('nightingale.enabled', false)
            || ! (bool) config('nightingale.features.messaging', false)
            || ! (bool) config('nightingale.staff_messaging.enabled', false)
            || config('nightingale.staff_messaging.governance_status') !== 'approved'
        ) {
            return $this->notFound();
        }

        return $next($request);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource is not available.',
            ],
        ], 404);
    }
}
