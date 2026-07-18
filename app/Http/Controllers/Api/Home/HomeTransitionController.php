<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeTransition;
use App\Services\Home\HomeTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Transitions board endpoints: inbound activation checklists, outbound
 * governed handoffs (care_transition transport + regional decision), episode
 * discharge → 30-day post-discharge cohort (ACUM-PRD-HAH-001 §7).
 */
class HomeTransitionController extends Controller
{
    public function __construct(private readonly HomeTransitionService $transitions) {}

    public function index(): JsonResponse
    {
        return response()->json($this->transitions->build());
    }

    public function completeChecklistItem(Request $request, string $transitionUuid): JsonResponse
    {
        $validated = $request->validate(['item' => ['required', 'string', 'max:60']]);

        $transition = HomeTransition::query()
            ->where('transition_uuid', $transitionUuid)
            ->where('is_deleted', false)
            ->firstOrFail();

        $updated = $this->transitions->completeChecklistItem($transition, $validated['item']);

        return response()->json(['transition' => [
            'transitionUuid' => $updated->transition_uuid,
            'status' => $updated->status,
            'checklist' => (array) $updated->checklist,
        ]]);
    }

    public function startOutbound(Request $request, string $episodeUuid): JsonResponse
    {
        $validated = $request->validate([
            'receiving_entity_type' => ['required', Rule::in(['pcp', 'home_health', 'snf', 'other'])],
            'handoff_owner' => ['nullable', 'string', 'max:190'],
        ]);

        $episode = $this->episode($episodeUuid);

        $result = $this->transitions->startOutbound(
            $episode,
            $validated['receiving_entity_type'],
            $validated['handoff_owner'] ?? (string) ($request->user()?->email ?? 'unknown'),
            $request->user()?->id,
        );

        return response()->json([
            'transition' => [
                'transitionUuid' => $result['transition']->transition_uuid,
                'status' => $result['transition']->status,
            ],
            'transportRequestId' => $result['transportRequestId'],
            'decisionId' => $result['decisionId'],
        ], 201);
    }

    public function discharge(Request $request, string $episodeUuid): JsonResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in(['routine_discharge', 'ed_return', 'readmitted', 'transferred', 'deceased', 'other'])],
        ]);

        $episode = $this->episode($episodeUuid);
        $discharged = $this->transitions->discharge($episode, $validated['disposition']);

        return response()->json(['episode' => [
            'episodeUuid' => $discharged->episode_uuid,
            'status' => $discharged->status,
            'disposition' => $discharged->disposition,
        ]]);
    }

    private function episode(string $episodeUuid): HomeEpisode
    {
        return HomeEpisode::query()
            ->where('episode_uuid', $episodeUuid)
            ->where('is_deleted', false)
            ->firstOrFail();
    }
}
