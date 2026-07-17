<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Models\Home\RpmAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient-alert acknowledgement workflow (ACUM-PRD-HAH-001 §4.2 / Phase 1
 * DoD). Acknowledge and resolve are HUMAN actions recorded with the acting
 * user — mirroring the Eddy doctrine that consequential loops keep a person
 * in them. Alerts are never deleted; resolution closes the row in place.
 */
class RpmAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $alerts = RpmAlert::query()
            ->with('episode:home_episode_id,patient_ref,condition_label')
            ->where('is_deleted', false)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->limit(100)
            ->get()
            ->map(fn (RpmAlert $alert): array => $this->present($alert));

        return response()->json(['alerts' => $alerts]);
    }

    public function acknowledge(Request $request, string $alertUuid): JsonResponse
    {
        $alert = RpmAlert::query()
            ->where('alert_uuid', $alertUuid)
            ->where('is_deleted', false)
            ->firstOrFail();

        if ($alert->status === 'open') {
            $alert->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => (string) ($request->user()?->email ?? $request->user()?->name ?? 'unknown'),
            ]);
        }

        return response()->json(['alert' => $this->present($alert->fresh())]);
    }

    public function resolve(Request $request, string $alertUuid): JsonResponse
    {
        $alert = RpmAlert::query()
            ->where('alert_uuid', $alertUuid)
            ->where('is_deleted', false)
            ->firstOrFail();

        if (in_array($alert->status, ['open', 'acknowledged'], true)) {
            $alert->update([
                'status' => 'resolved',
                // An unacked alert resolved directly still records the human.
                'acknowledged_at' => $alert->acknowledged_at ?? now(),
                'acknowledged_by' => $alert->acknowledged_by
                    ?? (string) ($request->user()?->email ?? $request->user()?->name ?? 'unknown'),
                'resolved_at' => now(),
                'resolved_by' => (string) ($request->user()?->email ?? $request->user()?->name ?? 'unknown'),
            ]);
        }

        return response()->json(['alert' => $this->present($alert->fresh())]);
    }

    /** @return array<string, mixed> */
    private function present(RpmAlert $alert): array
    {
        return [
            'alertUuid' => $alert->alert_uuid,
            'patientRef' => $alert->patient_ref,
            'conditionLabel' => $alert->episode?->condition_label,
            'ruleKey' => $alert->rule_key,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'openedAt' => $alert->opened_at?->toIso8601String(),
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
            'acknowledgedBy' => $alert->acknowledged_by,
            'resolvedAt' => $alert->resolved_at?->toIso8601String(),
            'value' => $alert->metadata['value'] ?? $alert->metadata['last_value'] ?? null,
            'unit' => $alert->metadata['unit'] ?? null,
            'display' => $alert->metadata['display'] ?? null,
            'breachCount' => $alert->metadata['breach_count'] ?? 1,
            'thresholdSource' => $alert->metadata['threshold_source'] ?? null,
        ];
    }
}
