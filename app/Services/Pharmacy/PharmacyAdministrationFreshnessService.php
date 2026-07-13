<?php

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The administration as-of freshness seam. Administration evidence is
 * warehouse-batch by baseline (§2: "Administration timestamps may be
 * nightly"), so every administration-dependent service and metric (Flow
 * Board, Cockpit, TAT Study) must consume THIS seam to label the latest
 * source cutoff and refuse real-time claims: a warehouse-class source is
 * classified `batch` (cutoff-qualified, never `fresh`) while its cutoff is
 * within the configured cadence tolerance and `stale` beyond it; only the
 * FUTURE real-time classes (`bcma_realtime`, `ras`) may ever classify
 * `fresh`, and they too demote to `stale` outside the operational window.
 * No administration evidence at all classifies `unknown` — unknown is not
 * compliant.
 */
final class PharmacyAdministrationFreshnessService
{
    /** @var list<string> */
    public const REALTIME_CLASSES = ['bcma_realtime', 'ras'];

    /**
     * Latest administration cutoff per governed source: one row per source
     * with the newest source_cutoff_at, the source class and extract that
     * asserted it, and the source label for display.
     *
     * @return Collection<int, object{source_id: int, source_key: string, source_name: string, administration_source_class: string, import_batch_key: string, source_cutoff_at: string}>
     */
    public function latestCutoffPerSource(): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT DISTINCT ON (administrations.source_id)
                administrations.source_id,
                sources.source_key,
                sources.source_name,
                administrations.administration_source_class,
                administrations.import_batch_key,
                administrations.source_cutoff_at
            FROM prod.rx_administrations AS administrations
            JOIN integration.sources AS sources ON sources.source_id = administrations.source_id
            ORDER BY administrations.source_id, administrations.source_cutoff_at DESC, administrations.rx_administration_id DESC
        SQL));
    }

    public function envelopeForSource(int $sourceId, ?CarbonImmutable $at = null): FreshnessEnvelope
    {
        $at ??= CarbonImmutable::now('UTC');
        $row = $this->latestCutoffPerSource()->first(fn (object $row): bool => (int) $row->source_id === $sourceId);
        if ($row === null) {
            return $this->unknown($at, 'No administration evidence has been imported for this source.');
        }

        return $this->envelope($row, $at);
    }

    /**
     * The single envelope administration-dependent metrics label: the most
     * severe classification across every administration source (stale over
     * batch over fresh), carrying that source's cutoff and label.
     */
    public function overallEnvelope(?CarbonImmutable $at = null): FreshnessEnvelope
    {
        $at ??= CarbonImmutable::now('UTC');
        $envelopes = $this->latestCutoffPerSource()->map(fn (object $row): FreshnessEnvelope => $this->envelope($row, $at));
        if ($envelopes->isEmpty()) {
            return $this->unknown($at, 'No administration evidence has been imported.');
        }

        $severity = ['fresh' => 1, 'batch' => 2, 'stale' => 3];

        return $envelopes
            ->sortBy(fn (FreshnessEnvelope $envelope): array => [
                -($severity[$envelope->status] ?? 0),
                $envelope->sourceCutoffAt?->getTimestamp() ?? PHP_INT_MIN,
            ])
            ->first();
    }

    private function envelope(object $row, CarbonImmutable $at): FreshnessEnvelope
    {
        $cutoff = CarbonImmutable::parse((string) $row->source_cutoff_at)->utc();
        $lag = max(0, (int) floor(($at->getTimestamp() - $cutoff->getTimestamp()) / 60));
        $label = (string) ($row->source_name ?: $row->source_key);

        if (in_array((string) $row->administration_source_class, self::REALTIME_CLASSES, true)) {
            $fresh = $lag <= max(1, (int) config('integrations.fresh_after_minutes', 15));

            return new FreshnessEnvelope(
                $fresh ? 'fresh' : 'stale',
                new DateTimeImmutable($at->toAtomString()),
                new DateTimeImmutable($cutoff->toAtomString()),
                $lag,
                $label,
                $fresh ? null : 'The real-time administration source is outside its operational freshness window.',
            );
        }

        $withinCadence = $lag <= max(1, (int) config('integrations.ancillary.warehouse_stale_after_minutes', 1860));

        return new FreshnessEnvelope(
            $withinCadence ? 'batch' : 'stale',
            new DateTimeImmutable($at->toAtomString()),
            new DateTimeImmutable($cutoff->toAtomString()),
            $lag,
            $label,
            $withinCadence
                ? 'Administration facts are warehouse-derived and current only as of the batch cutoff; they are never real-time.'
                : 'The latest administration cutoff exceeds the warehouse cadence tolerance; administration-dependent metrics are cutoff-qualified and cannot claim compliance.',
        );
    }

    private function unknown(CarbonImmutable $at, string $explanation): FreshnessEnvelope
    {
        return new FreshnessEnvelope(
            'unknown',
            new DateTimeImmutable($at->toAtomString()),
            null,
            null,
            'Administration warehouse',
            $explanation,
        );
    }
}
