<?php

namespace App\Services\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Ops\MetricDefinition;

/**
 * The SINGLE threshold→status resolver for Zephyrus 2.0 (plan Part VI §1).
 *
 * Extracted from the duplicated band methods in CommandCenterDataService
 * (bandHighBad/bandLowBad/bandProgress) — those now delegate here, and
 * OperationsAnalyticsService's copy converges in P5. resolveStatus() is the
 * 5-state engine driven by ops.metric_definitions band edges; the legacy
 * helpers preserve the historical 3-state contract byte-for-byte during the
 * transition (in-band deliberately maps to OK → canon 'success', because the
 * legacy vocabulary has no grey; the ISA-101 grey-baseline discipline starts
 * where metrics flow through resolveStatus + kpi_definitions).
 *
 * Comparator semantics (spec §4): direction 'down' means lower is better
 * (a value AT or ABOVE an edge breaches it); direction 'up' means higher is
 * better (a value AT or BELOW an edge breaches it). Resolution order is
 * crit → warn → ok → watch → normal. The watch band — the tier the spec
 * under-specifies — fires when the value is within `metadata.watch_band_pct`
 * (default 10%) of the warn edge on the good side.
 */
class StatusEngine
{
    public const DEFAULT_WATCH_BAND_PCT = 10.0;

    public function resolveStatus(float $value, MetricDefinition $definition): CockpitStatus
    {
        $edges = $definition->edges();
        $direction = $edges['direction'];

        if ($direction !== 'up' && $direction !== 'down') {
            return CockpitStatus::NORMAL;
        }

        $breached = fn (?float $edge): bool => $edge !== null
            && ($direction === 'down' ? $value >= $edge : $value <= $edge);

        if ($breached($edges['crit'])) {
            return CockpitStatus::CRIT;
        }

        if ($breached($edges['warn'])) {
            return CockpitStatus::WARN;
        }

        // "ok" is RATIONED (decision D3): it fires only when a definition
        // explicitly sets ok_edge — a metric merely in-band stays NORMAL/grey.
        // It resolves BEFORE watch (spec §4 order): an explicitly earned
        // on-target beats "trending toward the threshold", otherwise the
        // catalog's ok_edge == warn_edge seeds could never show green inside
        // the watch band. Watch still fires for definitions without ok_edge
        // and in the gap between the warn edge and a higher ok_edge.
        if ($edges['ok'] !== null
            && ($direction === 'down' ? $value <= $edges['ok'] : $value >= $edges['ok'])) {
            return CockpitStatus::OK;
        }

        if ($this->inWatchBand($value, $edges['warn'], $direction, $edges['watch_band_pct'])) {
            return CockpitStatus::WATCH;
        }

        return CockpitStatus::NORMAL;
    }

    private function inWatchBand(float $value, ?float $warnEdge, string $direction, float $bandPct): bool
    {
        if ($warnEdge === null || $bandPct <= 0) {
            return false;
        }

        $band = abs($warnEdge) * ($bandPct / 100);

        return $direction === 'down'
            ? $value < $warnEdge && $value >= $warnEdge - $band
            : $value > $warnEdge && $value <= $warnEdge + $band;
    }

    // -----------------------------------------------------------------------
    // Legacy 3-state helpers — behavior-preserving extraction of the
    // CommandCenterDataService band methods. Callers get identical strings
    // via ->canon(). Do not use these for new cockpit metrics.
    // -----------------------------------------------------------------------

    /**
     * Occupancy-style banding: high values are bad.
     * ≥ crit → CRIT, ≥ warn → WARN, else OK (legacy 'success').
     */
    public function highBad(float|int $value, float|int $crit, float|int $warn): CockpitStatus
    {
        if ($value >= $crit) {
            return CockpitStatus::CRIT;
        }
        if ($value >= $warn) {
            return CockpitStatus::WARN;
        }

        return CockpitStatus::OK;
    }

    /**
     * Target-style banding: low values are bad (e.g. DBN%, FCOTS%).
     * ≥ good → OK (legacy 'success'), ≥ warn → WARN, else CRIT.
     */
    public function lowBad(float|int $value, float|int $good, float|int $warn): CockpitStatus
    {
        if ($value >= $good) {
            return CockpitStatus::OK;
        }
        if ($value >= $warn) {
            return CockpitStatus::WARN;
        }

        return CockpitStatus::CRIT;
    }

    /**
     * OKR progress-style banding.
     */
    public function progress(int $pct): CockpitStatus
    {
        if ($pct >= 80) {
            return CockpitStatus::OK;
        }
        if ($pct >= 40) {
            return CockpitStatus::WARN;
        }

        return CockpitStatus::CRIT;
    }
}
