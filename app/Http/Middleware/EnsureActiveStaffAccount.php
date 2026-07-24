<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Independently fail closed when a stale or concurrently authenticated access
 * token outlives staff-account deactivation. The auth refresh route deliberately
 * does not use this middleware because its controller must revoke the complete
 * token family and record the account_inactive lifecycle reason.
 */
class EnsureActiveStaffAccount
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
                'code' => 'staff_access_unavailable',
                'message' => 'A valid staff credential is required.',
            ],
        ], 403);
    }
}
