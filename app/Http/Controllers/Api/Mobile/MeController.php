<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /api/mobile/v1/me              — profile, role, workflow, unit assignments.
 * PUT  /api/mobile/v1/me/preferences  — default workflow + theme (P0 subset).
 */
class MeController extends Controller
{
    use RendersMobileEnvelope;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->envelope([
            'id' => $user->getKey(),
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
            'is_admin' => $user->hasRole(['super-admin', 'admin']),
            'workflow_preference' => $user->workflow_preference,
            'must_change_password' => (bool) $user->must_change_password,
            'units' => $this->unitAssignments($user),
        ], links: ['web' => url('/home')]);
    }

    /**
     * User→unit assignments, PHI-free. Tolerates deployments where the optional
     * `user_unit` pivot hasn't been provisioned: the assignments simply resolve to
     * none rather than failing the whole profile (the mobile onboarding falls back
     * to the census unit list in that case).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function unitAssignments(\App\Models\User $user): \Illuminate\Support\Collection
    {
        try {
            return $user->units()->get()->map(fn ($unit) => [
                'unit_id' => $unit->unit_id,
                'name' => $unit->name,
                'role' => $unit->pivot->role,
                'is_primary' => (bool) $unit->pivot->is_primary,
            ])->values();
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return collect();
        }
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_workflow' => ['sometimes', 'string', 'in:superuser,rtdc,perioperative,emergency,improvement,transport'],
            'theme' => ['sometimes', 'string', 'in:dark,light,system'],
        ]);

        $user = $request->user();

        if (array_key_exists('default_workflow', $validated)) {
            $user->workflow_preference = $validated['default_workflow'];
            $user->save();
        }

        // Notification tiers + quiet hours arrive in Phase 1 with a dedicated
        // user_preferences table; theme is echoed back for now (client-persisted).
        return $this->envelope([
            'workflow_preference' => $user->workflow_preference,
            'theme' => $validated['theme'] ?? 'dark',
        ]);
    }
}
