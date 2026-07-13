<?php

namespace App\Services\Pharmacy;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\MedicationOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Projects warehouse/BCMA administration facts onto prod.rx_administrations.
 * Attachment is defensible-only: an administration links exclusively when its
 * source-scoped order keys match exactly one existing medication order —
 * zero candidates dead-letter as `unmatched_administration_order` and 2+
 * candidates as `ambiguous_administration_order`; the pipeline never guesses
 * and never fabricates a spine order from administration evidence. Corrected
 * warehouse rows (same row identity, new row version) APPEND a new versioned
 * row under the X-1 COALESCE row-version unique index — the prior fact is
 * never mutated — and current-version selection is derived.
 */
final class RxAdministrationProjector
{
    public function matchOrder(CanonicalOperationalEvent $event, int $sourceId): AncillaryOrder
    {
        $key = trim((string) ($event->payload['source_order_key'] ?? ''));
        if ($key === '') {
            throw new AncillaryIngestException('missing_order_identity', 'The administration event is missing its order linkage identity.', 'projection');
        }

        $candidates = AncillaryOrder::query()
            ->where('department', 'rx')
            ->where(fn ($query) => $query
                ->where('source_order_key', $key)
                ->orWhereRaw("metadata->>'reconciliation_key' = ?", [$key]))
            ->limit(3)
            ->get();
        $placer = trim((string) ($event->payload['placer_order_key'] ?? ''));
        if ($candidates->isEmpty() && $placer !== '') {
            $candidates = AncillaryOrder::query()
                ->where('department', 'rx')
                ->whereRaw("metadata->>'placer_order_key' = ?", [$placer])
                ->limit(3)
                ->get();
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }
        if ($candidates->isEmpty()) {
            throw new AncillaryIngestException(
                'unmatched_administration_order',
                'The administration row matches no existing medication order; it stays in data-quality reconciliation.',
                'projection',
                ['source_order_key' => $key],
            );
        }

        throw new AncillaryIngestException(
            'ambiguous_administration_order',
            'The administration row matches multiple medication orders; attachment is never guessed.',
            'projection',
            ['source_order_key' => $key],
        );
    }

    public function project(
        AncillaryOrder $order,
        MedicationOrder $medication,
        CanonicalOperationalEvent $event,
        int $sourceId,
    ): Administration {
        $payload = $event->payload;
        $key = trim((string) ($payload['source_administration_key'] ?? ''));
        $importBatchKey = trim((string) ($payload['import_batch_key'] ?? ''));
        if ($key === '' || $importBatchKey === '') {
            throw new AncillaryIngestException('missing_administration_identity', 'The administration projection requires its row and batch identity.', 'projection');
        }
        $rowVersion = trim((string) ($payload['source_row_version'] ?? '')) ?: null;
        $administeredAt = CarbonImmutable::parse((string) $payload['administered_at'])->utc();
        $cutoffAt = CarbonImmutable::parse((string) $payload['source_cutoff_at'])->utc();

        $existing = Administration::query()
            ->where('source_id', $sourceId)
            ->where('source_administration_key', $key)
            ->when(
                $rowVersion === null,
                fn ($query) => $query->whereNull('source_row_version'),
                fn ($query) => $query->where('source_row_version', $rowVersion),
            )
            ->first();
        if ($existing !== null) {
            if ((int) $existing->rx_order_id !== (int) $medication->rx_order_id) {
                throw new AncillaryIngestException(
                    'administration_identity_conflict',
                    'The administration identity is already linked to another medication order.',
                    'projection',
                    ['source_administration_key' => $key],
                );
            }
            if (! $existing->administered_at->equalTo($administeredAt)) {
                $metadata = is_array($existing->metadata) ? $existing->metadata : [];
                $metadata['replay_conflict'] = [
                    'retained' => $existing->administered_at->toIso8601String(),
                    'candidate' => $administeredAt->toIso8601String(),
                ];
                $existing->metadata = $metadata;
                $existing->save();
            }

            return $existing->refresh();
        }

        $prior = $this->currentVersion($sourceId, $key);
        $metadata = array_filter([
            'local_code' => $payload['administration_local_code'] ?? null,
            'ndc_code' => $payload['administration_ndc_code'] ?? null,
            'rxnorm_cui' => $payload['administration_rxnorm_cui'] ?? null,
            'medication_label' => $payload['administration_medication_label'] ?? null,
            'dosage_form' => $payload['dosage_form'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        if ($prior !== null) {
            $metadata['correction_of'] = [
                'rx_administration_id' => (int) $prior->rx_administration_id,
                'source_row_version' => $prior->source_row_version,
            ];
        }

        $administration = new Administration([
            'administration_uuid' => (string) Str::uuid(),
            'rx_order_id' => $medication->rx_order_id,
            'source_id' => $sourceId,
            'source_administration_key' => $key,
            'source_row_version' => $rowVersion,
            'import_batch_key' => $importBatchKey,
            'administration_source_class' => (string) ($payload['administration_source_class'] ?? 'bcma_warehouse'),
            'administration_status' => (string) ($payload['administration_status'] ?? 'given'),
            'administration_route' => $payload['administration_route'] ?? null,
            'administered_at' => $administeredAt,
            'source_cutoff_at' => $cutoffAt,
            'demo_owner' => $order->demo_owner,
            'metadata' => $metadata,
        ]);
        $administration->save();

        return $administration->refresh();
    }

    /**
     * Current-version selection for a source-scoped administration identity:
     * the newest appended row wins, exactly like the lab_results pattern —
     * prior versions remain as retained evidence.
     */
    public function currentVersion(int $sourceId, string $sourceAdministrationKey): ?Administration
    {
        return Administration::query()
            ->where('source_id', $sourceId)
            ->where('source_administration_key', $sourceAdministrationKey)
            ->orderByDesc('rx_administration_id')
            ->first();
    }
}
