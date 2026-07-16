<?php

namespace App\Http\Middleware;

use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    public function __construct(private readonly UserAuditRecorder $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $sessionVersion = $request->session()->get(AccountSessionService::SESSION_VERSION_KEY);
        $current = is_int($sessionVersion)
            && $sessionVersion === (int) $user->auth_session_version
            && (bool) $user->is_active;

        if ($current) {
            return $next($request);
        }

        $reason = ! $user->is_active ? 'account_inactive' : 'session_generation_stale';
        $this->audit->bestEffort('security.session.rejected', 'security', 'denied', [
            'request' => $request,
            'reason' => $reason,
            'target_type' => 'user',
            'target_id' => $user->getKey(),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login')->with('status', 'Your session was revoked. Please sign in again.');
    }
}
