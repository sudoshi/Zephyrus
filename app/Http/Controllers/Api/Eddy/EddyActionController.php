<?php

namespace App\Http\Controllers\Api\Eddy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eddy\EddyProposeActionRequest;
use App\Services\Eddy\EddyActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EddyActionController extends Controller
{
    public function __construct(private readonly EddyActionService $actions) {}

    /**
     * Propose a governance action. Reachable by a web-session human (the dock) or
     * by Eddy's scoped token (the agent callback). Requires the ops:draft ability —
     * session users hold it implicitly; a scoped token must carry it. Auto-approve
     * is honoured only when the caller can() ops:approve (humans), never for Eddy.
     */
    public function propose(EddyProposeActionRequest $request): JsonResponse
    {
        $user = $request->user();
        $human = $this->actsAsHuman($user);

        // A real scoped token must carry ops:draft. A web-session/SPA human always may.
        if (! $human && ! $user->tokenCan('ops:draft')) {
            return response()->json(['error' => 'Caller lacks the ops:draft ability.'], 403);
        }

        // Only a human may approve (Eddy's scoped token never holds ops:approve).
        $canApprove = $human || $user->tokenCan('ops:approve');
        $approve = $request->boolean('approve') && $canApprove;

        try {
            $result = $this->actions->propose($user, $request->validated(), $approve);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $result], 201);
    }

    /** Session (web) or SPA-stateful auth = a human; a real personal access token = the agent. */
    private function actsAsHuman(\App\Models\User $user): bool
    {
        $token = $user->currentAccessToken();

        return $token === null || $token instanceof \Laravel\Sanctum\TransientToken;
    }

    /**
     * Mint a short-TTL, ability-scoped token for the Eddy agent loop. NEVER carries
     * ops:approve — Eddy can draft but a human must approve. (First use of HasApiTokens.)
     */
    public function mintAgentToken(Request $request): JsonResponse
    {
        $abilities = (array) config('eddy.abilities.write', ['ops:read', 'ops:draft']);
        $expiresAt = now()->addMinutes(15);
        $token = $request->user()->createToken('eddy-agent', $abilities, $expiresAt);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'abilities' => $abilities,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /** The proposable-action catalog (tiers + risk) for the dock + the agent. */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->actions->catalog()]);
    }
}
