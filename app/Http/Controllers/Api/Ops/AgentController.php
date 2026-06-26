<?php

namespace App\Http\Controllers\Api\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\AgentRun;
use App\Services\Ops\Agents\AgentControlPlaneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(private readonly AgentControlPlaneService $agents) {}

    public function definitions(): JsonResponse
    {
        return response()->json([
            'data' => $this->agents->definitions()
                ->map(fn ($definition): array => [
                    'agentDefinitionId' => $definition->agent_definition_id,
                    'agentDefinitionUuid' => $definition->agent_definition_uuid,
                    'key' => $definition->agent_key,
                    'label' => $definition->label,
                    'description' => $definition->description,
                    'mode' => $definition->mode,
                    'status' => $definition->status,
                    'readOnly' => $definition->read_only,
                    'minimumRole' => $definition->minimum_role,
                    'toolAllowlist' => $definition->tool_allowlist ?? [],
                    'safetyPolicy' => $definition->safety_policy ?? [],
                ])
                ->values()
                ->all(),
        ]);
    }

    public function runCapacityCommander(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'objective' => ['nullable', 'string', 'max:1000'],
        ]);

        $run = $this->agents->runCapacityCommander(
            $request->user(),
            $validated['objective'] ?? 'Assess current capacity risk and recommend read-only next actions.',
        );

        return response()->json(['data' => $this->serializeRun($run)]);
    }

    public function runDataQuality(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'objective' => ['nullable', 'string', 'max:1000'],
        ]);

        $run = $this->agents->runDataQualityAgent(
            $request->user(),
            $validated['objective'] ?? 'Assess analytics source quality and governance readiness.',
        );

        return response()->json(['data' => $this->serializeRun($run)]);
    }

    public function show(AgentRun $run): JsonResponse
    {
        return response()->json(['data' => $this->serializeRun($run)]);
    }

    private function serializeRun(AgentRun $run): array
    {
        $run->loadMissing(['definition', 'toolCalls', 'evaluations', 'safetyEvents']);

        return [
            'agentRunId' => $run->agent_run_id,
            'agentRunUuid' => $run->agent_run_uuid,
            'agentKey' => $run->definition?->agent_key,
            'label' => $run->definition?->label,
            'status' => $run->status,
            'mode' => $run->mode,
            'objective' => $run->objective,
            'blockedReason' => $run->blocked_reason,
            'output' => $run->output_payload ?? [],
            'summary' => $run->summary_payload ?? [],
            'startedAtIso' => $run->started_at?->toIso8601String(),
            'completedAtIso' => $run->completed_at?->toIso8601String(),
            'toolCalls' => $run->toolCalls
                ->map(fn ($call): array => [
                    'agentToolCallId' => $call->agent_tool_call_id,
                    'toolKey' => $call->tool_key,
                    'status' => $call->status,
                    'readOnly' => $call->read_only,
                    'errorMessage' => $call->error_message,
                    'startedAtIso' => $call->started_at?->toIso8601String(),
                    'completedAtIso' => $call->completed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'evaluations' => $run->evaluations
                ->map(fn ($evaluation): array => [
                    'evaluationKey' => $evaluation->evaluation_key,
                    'status' => $evaluation->status,
                    'score' => (float) $evaluation->score,
                    'detail' => $evaluation->detail,
                ])
                ->values()
                ->all(),
            'safetyEvents' => $run->safetyEvents
                ->map(fn ($event): array => [
                    'eventType' => $event->event_type,
                    'severity' => $event->severity,
                    'status' => $event->status,
                    'detail' => $event->detail,
                ])
                ->values()
                ->all(),
        ];
    }
}
