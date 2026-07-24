<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\MobileTokenSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Observe recent staff-family activity without treating device metadata as an
 * authorization claim. Role, capability, unit, and facility decisions remain
 * request-time server evaluations in their existing middleware and policies.
 */
class TouchMobileTokenSession
{
    public function __construct(private readonly MobileTokenSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user instanceof User && $token instanceof PersonalAccessToken) {
            $this->sessions->touchForToken($user, $token);
        }

        return $next($request);
    }
}
