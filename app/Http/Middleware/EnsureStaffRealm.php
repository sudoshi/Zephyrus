<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Prevent non-staff Sanctum tokenable models from entering the staff BFF. */
class EnsureStaffRealm
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
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
