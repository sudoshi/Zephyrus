<?php

namespace App\Services\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use Illuminate\Support\Facades\DB;

class AncillaryProjectionRebuilder
{
    public function rebuild(AncillaryOrder $order): AncillaryOrder
    {
        $selected = DB::table('prod.ancillary_current_assertions as assertions')
            ->join('hosp_ref.ancillary_milestone_types as types', 'types.code', '=', 'assertions.milestone_code')
            ->where('assertions.ancillary_order_id', $order->ancillary_order_id)
            ->where('types.department', $order->department)
            ->select([
                'assertions.milestone_code',
                'assertions.occurred_at',
                'assertions.received_at',
                'assertions.disagreement_seconds',
                'types.phase',
                'types.ordinal',
                'types.is_terminal',
            ])
            ->orderByDesc('types.ordinal')
            ->orderByDesc('assertions.occurred_at')
            ->get();

        if ($selected->isEmpty()) {
            return $order->refresh();
        }

        $head = $selected->first();
        $sourceCutoff = $selected->max('received_at');
        $tolerance = max(0, (int) config('integrations.ancillary.assertion_conflict_tolerance_seconds', 300));
        $conflicts = $selected
            ->filter(fn (object $row): bool => (int) ($row->disagreement_seconds ?? 0) > $tolerance)
            ->map(fn (object $row): array => [
                'milestoneCode' => $row->milestone_code,
                'disagreementSeconds' => (int) $row->disagreement_seconds,
            ])
            ->values()
            ->all();
        $metadata = $order->metadata;
        unset($metadata['data_quality_conflicts'], $metadata['has_source_conflict']);
        if ($conflicts !== []) {
            $metadata['has_source_conflict'] = true;
            $metadata['data_quality_conflicts'] = $conflicts;
        }

        $order->update([
            'current_state' => (bool) $head->is_terminal
                ? strtolower((string) $head->milestone_code)
                : (string) $head->phase,
            'current_milestone_code' => $head->milestone_code,
            'current_milestone_at' => $head->occurred_at,
            'terminal_at' => (bool) $head->is_terminal ? $head->occurred_at : null,
            'source_cutoff_at' => $sourceCutoff,
            'metadata' => $metadata,
        ]);

        return $order->refresh();
    }
}
