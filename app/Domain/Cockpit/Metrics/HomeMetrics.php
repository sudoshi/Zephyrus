<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Facades\DB;

/**
 * Home Hospital domain provider (ACUM-PRD-HAH-001 §9) — the virtual ward on
 * the same instrument as ED and RTDC. Emits nothing while the module flag is
 * off, so the cockpit is byte-identical on deployments without the module.
 *
 * Every value is a cheap aggregate over the prod.home_* / prod.rpm_* tables;
 * alert candidacy is governed entirely by which seeded definitions carry an
 * alert_template (the Earned-Red ration — four alerting rows, no per-vital
 * noise at house altitude).
 */
class HomeMetrics extends BaseMetrics
{
    private const OFFLINE_GAP_MINUTES = 60;

    public function domain(): string
    {
        return 'home';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        if (! (bool) config('home_hospital.enabled')) {
            return [];
        }

        $ward = $this->wardCensus();

        return $this->compact([
            $ward === null ? null : $this->fromKey($ctx, 'home.census_occupancy', $ward['pct'], [
                'sub' => "{$ward['occupied']} of {$ward['capacity']} slots",
            ]),
            $this->fromKey($ctx, 'home.unacked_critical_vitals', $this->unackedCriticalVitals($ctx)),
            $this->fromKey($ctx, 'home.escalation_response_p90', $this->escalationResponseP90()),
            $this->fromKey($ctx, 'home.visit_compliance_today', $this->visitComplianceToday()),
            $this->fromKey($ctx, 'home.device_offline_pct', $this->deviceOfflinePct()),
            $this->fromKey($ctx, 'home.rpm_adherence', $this->rpmAdherence()),
            $this->fromKey($ctx, 'home.escalation_rate_7d', $this->escalationRate7d()),
            $this->fromKey($ctx, 'home.referral_conversion_7d', $this->referralConversion7d()),
            $this->fromKey($ctx, 'home.avoided_bed_days_mtd', $this->avoidedBedDaysMtd()),
        ]);
    }

    /** @return array{occupied:int,capacity:int,pct:float}|null */
    private function wardCensus(): ?array
    {
        $row = DB::selectOne("
            SELECT count(*) FILTER (WHERE b.status = 'occupied') AS occupied, count(*) AS capacity
            FROM prod.beds b
            JOIN prod.units u ON u.unit_id = b.unit_id
            WHERE u.type = 'virtual_home' AND u.is_deleted = false AND b.is_deleted = false
        ");

        $capacity = (int) ($row->capacity ?? 0);
        if ($capacity === 0) {
            return null;
        }

        $occupied = (int) $row->occupied;

        return ['occupied' => $occupied, 'capacity' => $capacity, 'pct' => round(100.0 * $occupied / $capacity, 1)];
    }

    private function unackedCriticalVitals(SnapshotContext $ctx): float
    {
        return (float) DB::table('prod.rpm_alerts')
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->where('is_deleted', false)
            ->count();
    }

    private function escalationResponseP90(): ?float
    {
        $row = DB::selectOne("
            SELECT percentile_cont(0.9) WITHIN GROUP (ORDER BY response_minutes) AS p90
            FROM prod.home_escalations
            WHERE response_minutes IS NOT NULL AND is_deleted = false
              AND initiated_at >= ?::timestamptz
        ", [now()->subDays(7)->toIso8601String()]);

        return $row->p90 !== null ? round((float) $row->p90, 1) : null;
    }

    /** Percent of DUE waiver-required visits completed today (future visits are not yet non-compliant). */
    private function visitComplianceToday(): ?float
    {
        $row = DB::selectOne("
            SELECT count(*) FILTER (WHERE status = 'completed') AS done, count(*) AS due
            FROM prod.home_visits
            WHERE is_waiver_required = true AND is_deleted = false
              AND scheduled_start >= date_trunc('day', now())
              AND scheduled_start <= now()
              AND status NOT IN ('cancelled')
        ");

        $due = (int) ($row->due ?? 0);

        return $due > 0 ? round(100.0 * (int) $row->done / $due, 1) : null;
    }

    private function deviceOfflinePct(): ?float
    {
        $row = DB::selectOne('
            SELECT count(*) FILTER (WHERE last_seen_at IS NULL OR last_seen_at < ?::timestamptz) AS offline,
                   count(*) AS assigned
            FROM prod.rpm_kits
            WHERE status = ? AND is_deleted = false
        ', [now()->subMinutes(self::OFFLINE_GAP_MINUTES)->toIso8601String(), 'assigned']);

        $assigned = (int) ($row->assigned ?? 0);

        return $assigned > 0 ? round(100.0 * (int) $row->offline / $assigned, 1) : null;
    }

    /** Received vs expected readings across active enrollments over the 6h adherence window. */
    private function rpmAdherence(): ?float
    {
        $enrollments = DB::table('prod.rpm_enrollments')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->get(['rpm_enrollment_id', 'monitoring_plan']);

        if ($enrollments->isEmpty()) {
            return null;
        }

        $windowHours = (int) config('home_hospital.hews.adherence_window_hours', 6);
        $since = now()->subHours($windowHours);

        $received = DB::table('prod.rpm_observations')
            ->whereIn('rpm_enrollment_id', $enrollments->pluck('rpm_enrollment_id'))
            ->where('observed_at', '>=', $since)
            ->where('is_deleted', false)
            ->selectRaw('rpm_enrollment_id, count(*) AS n')
            ->groupBy('rpm_enrollment_id')
            ->pluck('n', 'rpm_enrollment_id');

        $expectedTotal = 0;
        $receivedTotal = 0;
        foreach ($enrollments as $enrollment) {
            $plan = json_decode((string) $enrollment->monitoring_plan, true) ?: [];
            foreach ((array) ($plan['cadence_minutes'] ?? []) as $minutes) {
                $minutes = (int) $minutes;
                if ($minutes > 0) {
                    $expectedTotal += (int) floor(($windowHours * 60) / $minutes);
                }
            }
            $receivedTotal += (int) ($received[$enrollment->rpm_enrollment_id] ?? 0);
        }

        return $expectedTotal > 0 ? round(min(100.0, 100.0 * $receivedTotal / $expectedTotal), 1) : null;
    }

    private function escalationRate7d(): ?float
    {
        $episodeDays = DB::selectOne("
            SELECT COALESCE(SUM(
                EXTRACT(EPOCH FROM (LEAST(COALESCE(ended_at, now()), now()) - GREATEST(started_at, now() - interval '7 days')))
            ) / 86400.0, 0) AS days
            FROM prod.home_episodes
            WHERE is_deleted = false AND started_at IS NOT NULL
              AND started_at <= now()
              AND COALESCE(ended_at, now()) >= now() - interval '7 days'
        ");

        $days = (float) ($episodeDays->days ?? 0);
        if ($days <= 0) {
            return null;
        }

        $escalations = (int) DB::table('prod.home_escalations')
            ->where('is_deleted', false)
            ->where('initiated_at', '>=', now()->subDays(7))
            ->count();

        return round(100.0 * $escalations / $days, 1);
    }

    private function referralConversion7d(): ?float
    {
        $row = DB::selectOne("
            SELECT count(*) FILTER (WHERE status = 'activated') AS activated, count(*) AS total
            FROM prod.home_referrals
            WHERE is_deleted = false AND referred_at >= now() - interval '7 days'
        ");

        $total = (int) ($row->total ?? 0);

        return $total > 0 ? round(100.0 * (int) $row->activated / $total, 1) : null;
    }

    /** Every active home episode-day this month is an avoided occupied bed-day (§6.2). */
    private function avoidedBedDaysMtd(): float
    {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(
                EXTRACT(EPOCH FROM (LEAST(COALESCE(ended_at, now()), now()) - GREATEST(started_at, date_trunc('month', now())))) / 86400.0
            ), 0) AS days
            FROM prod.home_episodes
            WHERE is_deleted = false AND started_at IS NOT NULL
              AND started_at <= now()
              AND COALESCE(ended_at, now()) >= date_trunc('month', now())
        ");

        return round((float) ($row->days ?? 0), 1);
    }
}
