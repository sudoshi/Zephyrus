<?php

namespace App\Services\Demo;

use Carbon\CarbonImmutable;

/**
 * The one rolling demo clock (plan §4.1 / FEEDBACK Wave 1).
 *
 * Every rolling-demo refresh and every read-side invariant check must derive its
 * sense of "now" from a SINGLE immutable anchor, not from ad-hoc `now()` /
 * `today()` / `MAX(some_column)` scattered across services. Today the demo is
 * incoherent precisely because different surfaces invent different anchors
 * (TriageService advances to MAX(arrived_at); TreatmentService uses wall-clock;
 * periop advances to MAX(surgery_date); staffing is strict today()) — see
 * FEEDBACK §3.4b. This class is the antidote: construct it once per batch, pass
 * it explicitly, and every timestamp in the batch shares the same frame.
 *
 * The operational window is [anchor - 24h, anchor + 24h]. Historical analytic
 * cohorts may extend farther back but must never masquerade as current
 * operational data (plan §4.1).
 *
 * Tests freeze the anchor by constructing with an explicit CarbonImmutable, so
 * they never depend on wall-clock execution speed.
 */
final class DemoClock
{
    private readonly CarbonImmutable $anchor;

    private readonly int $windowHours;

    /**
     * @param  CarbonImmutable|string|null  $anchor  Explicit anchor, an ISO-8601
     *   string, the literal "now", or null (= now). Frozen at construction.
     * @param  int  $windowHours  Full operational window width; split evenly
     *   around the anchor (default 48 → ±24h).
     */
    public function __construct(CarbonImmutable|string|null $anchor = null, int $windowHours = 48)
    {
        $this->anchor = match (true) {
            $anchor instanceof CarbonImmutable => $anchor,
            $anchor === null, $anchor === '', $anchor === 'now' => CarbonImmutable::now(),
            default => CarbonImmutable::parse($anchor),
        };
        $this->windowHours = max(2, $windowHours);
    }

    /** Parse the conventional `--anchor` command option ("now" or ISO-8601). */
    public static function fromOption(?string $anchor, int $windowHours = 48): self
    {
        return new self($anchor, $windowHours);
    }

    /** The single immutable moment the whole batch is anchored to. */
    public function anchor(): CarbonImmutable
    {
        return $this->anchor;
    }

    /** Start of the operational window (anchor - windowHours/2). */
    public function windowStart(): CarbonImmutable
    {
        return $this->anchor->subHours(intdiv($this->windowHours, 2));
    }

    /** End of the operational window (anchor + windowHours/2). Forecast horizon. */
    public function windowEnd(): CarbonImmutable
    {
        return $this->anchor->addHours(intdiv($this->windowHours, 2));
    }

    /** Full window width in hours. */
    public function windowHours(): int
    {
        return $this->windowHours;
    }

    /** True when $at falls inside [windowStart, windowEnd]. */
    public function contains(CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->windowStart())
            && $at->lessThanOrEqualTo($this->windowEnd());
    }

    /** ISO-8601 anchor — used as the ledger/refresh-run key and payload stamp. */
    public function key(): string
    {
        return $this->anchor->toIso8601String();
    }
}
