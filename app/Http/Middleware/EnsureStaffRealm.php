<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent non-staff or inactive Sanctum principals from entering the staff BFF.
 *
 * Normal account deactivation revokes every credential transactionally. This
 * request-time check is an independent fail-closed boundary for stale,
 * concurrently authenticated, or externally invalidated access tokens.
 */
class EnsureStaffRealm
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) $user->is_active) {
            return $this->denied();
        }

        return $next($request);
    }

    private function denied(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'staff_realm_required',
                'message' => 'A valid staff credential is required.',
            ],
        ], 403);
    }
}
