<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Exceptions\Rounds\RoundConflictException;
use App\Exceptions\Rounds\RoundPolicyException;
use App\Exceptions\Rounds\RoundTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Rounds\RoundContribution;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundRun;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for the rounds API: opaque-UUID model resolution and the shared
 * error mapping. Controllers validate and delegate — transitions, priority,
 * access, and messaging all live in the services (plan §13.3).
 *
 * Conflict responses (409) include the current board projection so a client
 * holding a stale version can recover without a second round trip.
 */
abstract class RoundsController extends Controller
{
    public function __construct(protected readonly RoundProjectionService $projection) {}

    protected function resolveRun(string $runUuid): RoundRun
    {
        return RoundRun::query()->where('run_uuid', $runUuid)->firstOrFail();
    }

    protected function resolvePatient(string $roundPatientUuid): RoundPatient
    {
        return RoundPatient::query()->where('round_patient_uuid', $roundPatientUuid)->firstOrFail();
    }

    protected function resolveContribution(string $contributionUuid): RoundContribution
    {
        return RoundContribution::query()->where('contribution_uuid', $contributionUuid)->firstOrFail();
    }

    protected function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : $key;
    }

    /**
     * Execute a command and map domain exceptions onto the API contract:
     * conflict/transition -> 409 (+ current projection when a run is known),
     * policy -> 422. AuthorizationException bubbles to the framework's 403.
     */
    protected function guard(callable $fn, ?RoundRun $run = null, ?Request $request = null): JsonResponse
    {
        try {
            return $fn();
        } catch (RoundConflictException|RoundTransitionException $e) {
            $payload = ['error' => ['code' => 'rounds_conflict', 'message' => $e->getMessage()]];

            if ($run !== null && $request !== null && $request->user() !== null) {
                $payload['current'] = $this->projection->board($run->refresh(), $request->user());
            }

            return response()->json($payload, 409);
        } catch (RoundPolicyException $e) {
            return response()->json([
                'error' => ['code' => 'rounds_policy', 'message' => $e->getMessage()],
            ], 422);
        }
    }
}
