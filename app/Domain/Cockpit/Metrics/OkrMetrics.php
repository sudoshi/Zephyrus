<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\StatusEngine;
use App\Services\Rtdc\DemandForecastService;
use App\Support\Cockpit\MetricValue;

/**
 * Executive OKR scorecard (spec §2.1) — 13 registry cards (9 core + 4 service
 * sectors added 2026-07-19). Runs LAST so it reuses values other providers
 * already computed this snapshot (open shifts, hand hygiene, worked/UOS, and
 * the radiology/lab/pharmacy/home headline signals) instead of re-querying.
 * Live where the house has the source (ED LOS admitted, DBN, readmit, midnight
 * occupancy via the RTDC by_midnight horizon); demo elsewhere.
 */
class OkrMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly DemandForecastService $forecast,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'okr';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        return $this->compact([
            // P7: reuse the now-live quality/financial values, falling back to
            // the seeded OKR constant when the live source is absent so the
            // 9-card registry never shows a hole (okr.hcahps stays pure demo:
            // HCAHPS is an external survey with no live source).
            $this->reuse($ctx, 'okr.sepsis_3hr', 'quality.sepsis_3hr') ?? $this->demo($ctx, 'okr.sepsis_3hr'),
            $this->reuse($ctx, 'okr.ed_los_admit', 'ed.los_admit'),
            $this->fromLegacy($ctx, 'okr.dc_before_noon', 'dbn'),
            $this->fromKey($ctx, 'okr.occupancy_midnight', $this->midnightOccupancy($ctx), [
                'sub' => 'projected at midnight',
            ]),
            $this->reuse($ctx, 'okr.open_shifts', 'staffing.open_shifts'),
            $this->reuse($ctx, 'okr.hand_hygiene', 'quality.hand_hygiene') ?? $this->demo($ctx, 'okr.hand_hygiene'),
            $this->reuse($ctx, 'okr.worked_per_uos', 'financial.worked_per_uos') ?? $this->demo($ctx, 'okr.worked_per_uos'),
            $this->demo($ctx, 'okr.hcahps'),
            $this->fromLegacy($ctx, 'okr.readmit_30d', 'readmission'),
            // Service-sector OKRs (2026-07-19): reuse each sector's live headline
            // signal, falling back to the seeded constant so the scorecard shows
            // the sector even in an environment without its ancillary/home feed.
            $this->reuse($ctx, 'okr.rad_sla_breaches', 'flow.ancillary_rad_open_breaches') ?? $this->demo($ctx, 'okr.rad_sla_breaches'),
            $this->reuse($ctx, 'okr.lab_stat_tat', 'flow.ancillary_lab_stat_compliance') ?? $this->demo($ctx, 'okr.lab_stat_tat'),
            $this->reuse($ctx, 'okr.rx_stockouts', 'flow.ancillary_rx_shortage_stockouts') ?? $this->demo($ctx, 'okr.rx_stockouts'),
            $this->reuse($ctx, 'okr.hah_avoided_bed_days', 'home.avoided_bed_days_mtd') ?? $this->demo($ctx, 'okr.hah_avoided_bed_days'),
        ]);
    }

    /**
     * Re-band an already-emitted value under the OKR's own definition,
     * carrying over demo provenance when the source was mocked.
     */
    private function reuse(SnapshotContext $ctx, string $okrKey, string $sourceKey): ?MetricValue
    {
        $source = $ctx->emittedValue($sourceKey);

        if ($source === null) {
            return null;
        }

        $overrides = ['trend' => $source->trend, 'sub' => $source->sub];

        if (($source->metadata['provenance'] ?? null) === 'demo') {
            $overrides['metadata'] = ['provenance' => 'demo'];
        }

        return $this->fromKey($ctx, $okrKey, $source->value, $overrides);
    }

    /**
     * Midnight occupancy % = the RTDC by_midnight predicted census over
     * staffed beds (plan mapping: DemandForecastService midnight horizon).
     * Falls back to current occupancy when no forecast rows exist.
     */
    private function midnightOccupancy(SnapshotContext $ctx): ?float
    {
        $staffed = (int) array_sum(array_column($ctx->legacy['unitCensus'] ?? [], 'staffed'));

        if ($staffed > 0) {
            try {
                $horizons = collect($this->forecast->build()['horizons'] ?? []);
                $midnight = $horizons->firstWhere('horizon', 'by_midnight');

                if (is_array($midnight) && isset($midnight['predictedCensus'])) {
                    return round(100.0 * (int) $midnight['predictedCensus'] / $staffed);
                }
            } catch (\Throwable) {
                // fall through to current occupancy
            }
        }

        return $ctx->legacyValue('occupancy');
    }
}
