<?php

namespace App\Services\Ops;

use App\Models\Barrier;
use App\Models\Ops\OperationalAction;
use App\Models\PdsaCycle;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation, seam 2: the corrective-action
 * EXECUTOR. This is the one place a governed AI draft becomes a real domain row.
 *
 * The copilot only ever PROPOSES (Recommendation → OperationalAction → Approval,
 * all draft/pending); nothing touches prod.* until a human approves. On approval,
 * OperationalActionLifecycleService::decideApproval() calls materialize() for the
 * two copilot corrective-action types — and only then is a prod.pdsa_cycles row
 * written from the drafted plan that has been riding in the action payload.
 *
 * Three invariants:
 *   - ADDITIVE: only the two copilot draft types materialize; every other action
 *     type (flag_barrier, bed placement, …) is a no-op and returns null.
 *   - IDEMPOTENT: the created cycle id is written back into the action payload, so a
 *     re-approval (or a retry) returns the same cycle instead of minting a second.
 *   - ATOMIC: it runs inside decideApproval()'s transaction, so a write failure
 *     rolls the approval back — an approval never records without its domain effect.
 *
 * Writing pdsa_cycle_id back onto the action also lets the existing
 * InterventionAttributionService (pdsaCycleIdForAction) link the intervention to
 * this cycle when the action later completes — no extra wiring needed here.
 */
final class CorrectiveActionExecutor
{
    /**
     * The copilot corrective-action types that materialize a PDSA cycle. Kept in
     * sync with EddyActionService::CATALOG's copilot entries (§X.8.3) and reused by
     * FlowReviewService to count actions_pending.
     *
     * @var list<string>
     */
    public const MATERIALIZES = ['propose_pdsa_cycle', 'propose_pathway_correction'];

    /**
     * Materialize the approved corrective action into a prod.pdsa_cycles row, and
     * close the loop by pointing the originating barrier at it. Returns the cycle,
     * or null when the action type does not materialize.
     */
    public function materialize(OperationalAction $action): ?PdsaCycle
    {
        if (! in_array($action->action_type, self::MATERIALIZES, true)) {
            return null;
        }

        $payload = is_array($action->payload) ? $action->payload : [];

        // Idempotency: a cycle was already minted for this action — never duplicate.
        if (! empty($payload['pdsa_cycle_id'])) {
            return PdsaCycle::find($payload['pdsa_cycle_id']);
        }

        $recommendation = $action->recommendation;
        $barrier = $recommendation?->barrier_id ? Barrier::find($recommendation->barrier_id) : null;

        $content = $this->pdsaContent($payload, $recommendation?->title, $recommendation?->rationale);

        $cycle = PdsaCycle::create([
            'title' => $content['title'],
            // Anchor the cycle to the barrier's unit when the link exists; otherwise
            // it is a house-level improvement (unit_id is nullable).
            'unit_id' => $barrier?->unit_id,
            'status' => 'planned', // governed draft → planned; a human runs it next.
            'objective' => $content['objective'],
            'rationale' => $content['rationale'],
            'prediction' => $content['prediction'],
            'started_at' => now(),
            'is_deleted' => false,
        ]);

        // Stamp the id back so re-approval is idempotent and the downstream
        // intervention-attribution sync can find this cycle from the action.
        $payload['pdsa_cycle_id'] = $cycle->pdsa_cycle_id;
        $action->forceFill(['payload' => $payload])->save();

        // Seam 3: the barrier now points at its resolution cycle (first writer wins,
        // so an already-linked barrier is left untouched).
        if ($barrier !== null && $barrier->pdsa_cycle_id === null) {
            $barrier->forceFill(['pdsa_cycle_id' => $cycle->pdsa_cycle_id])->save();
        }

        return $cycle;
    }

    /**
     * Resolve the PDSA fields. A propose_pdsa_cycle draft carries a full plan under
     * payload.pdsa; a propose_pathway_correction has no plan, so it falls back to the
     * recommendation's own title/rationale — either way we get a coherent cycle.
     *
     * @param  array<string, mixed>  $payload
     * @return array{title: string, objective: ?string, rationale: ?string, prediction: ?string}
     */
    private function pdsaContent(array $payload, ?string $recTitle, ?string $recRationale): array
    {
        $pdsa = is_array($payload['pdsa'] ?? null) ? $payload['pdsa'] : [];

        $title = $this->str($pdsa['title'] ?? null) ?? $this->str($recTitle) ?? 'Corrective action';

        return [
            'title' => $title,
            'objective' => $this->str($pdsa['objective'] ?? null) ?? $this->str($recRationale),
            'rationale' => $this->str($pdsa['hypothesis'] ?? null) ?? $this->str($recRationale),
            'prediction' => $this->str($pdsa['prediction'] ?? null),
        ];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
