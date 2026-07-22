<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCarePathwayGovernanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('care-pathways.governance_enabled', false)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        }

        return $next($request);
    }
}
