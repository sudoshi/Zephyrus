<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject Sanctum's first-party/session shortcut at machine integration routes.
 *
 * `auth:sanctum` runs first and authenticates the bearer. This second boundary
 * proves that authentication came from a persisted PAT and that the requested
 * machine ability was granted explicitly (a wildcard human token is not enough).
 */
class RequireMachineToken
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $authorization = (string) $request->header('Authorization', '');
        $token = $request->user()?->currentAccessToken();

        if (! preg_match('/^Bearer\s+\S+$/i', $authorization)
            || ! $token instanceof PersonalAccessToken) {
            return $this->forbidden(
                'machine_token_required',
                'This integration route requires a dedicated bearer token.',
            );
        }

        if ($request->user()?->is_active !== true) {
            return $this->forbidden(
                'machine_identity_inactive',
                'The machine identity is inactive.',
            );
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        if (! in_array($ability, $abilities, true)) {
            return $this->forbidden(
                'machine_ability_required',
                "The machine token must explicitly grant {$ability}.",
            );
        }

        return $next($request);
    }

    private function forbidden(string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => request()->attributes->get(AssignRequestIdentity::ATTRIBUTE),
        ], 403);
    }
}
