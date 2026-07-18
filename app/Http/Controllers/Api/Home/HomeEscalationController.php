<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeEscalation;
use App\Models\Home\RpmAlert;
use App\Services\Home\HomeEscalationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escalation workflow endpoints (ACUM-PRD-HAH-001 §4.2). Every transition is
 * a human action over the authenticated session; response timers derive from
 * the stored timing chain, never from the client.
 */
class HomeEscalationController extends Controller
{
    public function __construct(private readonly HomeEscalationService $escalations) {}

    public function index(): JsonResponse
    {
        $open = HomeEscalation::query()
            ->with('episode:home_episode_id,patient_ref,condition_label')
            ->whereIn('status', ['open', 'responding'])
            ->where('is_deleted', false)
            ->orderBy('initiated_at')
            ->get()
            ->map(fn (HomeEscalation $e): array => $this->present($e));

        return response()->json(['escalations' => $open]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'episode_uuid' => ['required', 'string'],
            'trigger_type' => ['required', Rule::in(['critical_vital', 'clinical_deterioration', 'patient_request', 'caregiver_request', 'device_failure', 'other'])],
            'alert_uuid' => ['nullable', 'string'],
            'response_mode' => ['nullable', Rule::in(['tele_assessment', 'field_dispatch', 'ems', 'ed_return'])],
        ]);

        $episode = HomeEpisode::query()
            ->where('episode_uuid', $validated['episode_uuid'])
            ->where('is_deleted', false)
            ->firstOrFail();

        $alert = isset($validated['alert_uuid'])
            ? RpmAlert::query()->where('alert_uuid', $validated['alert_uuid'])->where('is_deleted', false)->first()
            : null;

        $escalation = $this->escalations->open(
            $episode,
            $validated['trigger_type'],
            $alert,
            $validated['response_mode'] ?? null,
        );

        return response()->json(['escalation' => $this->present($escalation)], 201);
    }

    public function dispatchResponse(Request $request, string $escalationUuid): JsonResponse
    {
        $validated = $request->validate([
            'response_mode' => ['required', Rule::in(['tele_assessment', 'field_dispatch', 'ems', 'ed_return'])],
        ]);

        $escalation = $this->openEscalation($escalationUuid);

        return response()->json([
            'escalation' => $this->present($this->escalations->markDispatched($escalation, $validated['response_mode'])),
        ]);
    }

    public function arrive(string $escalationUuid): JsonResponse
    {
        $escalation = $this->openEscalation($escalationUuid);

        return response()->json([
            'escalation' => $this->present($this->escalations->markArrived($escalation)),
        ]);
    }

    public function resolve(Request $request, string $escalationUuid): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['managed_at_home', 'ed_return', 'readmitted', 'deceased', 'other'])],
        ]);

        $escalation = $this->openEscalation($escalationUuid);

        return response()->json([
            'escalation' => $this->present($this->escalations->resolve($escalation, $validated['outcome'])),
        ]);
    }

    private function openEscalation(string $escalationUuid): HomeEscalation
    {
        return HomeEscalation::query()
            ->where('escalation_uuid', $escalationUuid)
            ->whereIn('status', ['open', 'responding'])
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(HomeEscalation $e): array
    {
        return [
            'escalationUuid' => $e->escalation_uuid,
            'patientRef' => $e->patient_ref,
            'conditionLabel' => $e->episode?->condition_label,
            'triggerType' => $e->trigger_type,
            'responseMode' => $e->response_mode,
            'status' => $e->status,
            'initiatedAt' => $e->initiated_at?->toIso8601String(),
            'dispatchedAt' => $e->dispatched_at?->toIso8601String(),
            'arrivedAt' => $e->arrived_at?->toIso8601String(),
            'resolvedAt' => $e->resolved_at?->toIso8601String(),
            'responseMinutes' => $e->response_minutes,
            // Live timer input: minutes since initiation while unresolved.
            'elapsedMinutes' => $e->status === 'resolved' || $e->initiated_at === null
                ? null
                : max(0, (int) round($e->initiated_at->diffInMinutes(now()))),
            'outcome' => $e->outcome,
        ];
    }
}
