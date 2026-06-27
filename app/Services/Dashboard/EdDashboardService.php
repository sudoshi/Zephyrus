<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Emergency Department dashboard payload from the live `prod` schema.
 *
 * Returns EXACTLY the four mock shapes consumed by resources/js/Pages/Dashboard/ED.jsx
 * (edMetrics, performanceMetrics, patientStatusBoard, alertsData) but computed from
 * seeded prod.* tables. Deterministic and safe on empty tables (returns zeros / empty
 * arrays, never throws). Query idioms mirror App\Services\CommandCenterDataService.
 */
class EdDashboardService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Hourly buckets rendered by the Wait Time Trends chart. */
    private const TREND_HOURS = [0, 4, 8, 12, 16, 20];

    /** Performance targets (configured, not live-derived). */
    private const TARGET_DOOR_TO_PROVIDER = 30; // minutes

    private const TARGET_LWBS = 2.0;             // percent

    private const TARGET_LOS_ADMITTED = 240;     // minutes

    private const TARGET_LOS_DISCHARGED = 160;   // minutes

    /**
     * Assemble the full ED dashboard payload.
     *
     * @return array{edMetrics:array<string,mixed>,performanceMetrics:array<string,mixed>,patientStatusBoard:list<array<string,mixed>>,alertsData:array{alerts:list<array<string,mixed>>}}
     */
    public function build(): array
    {
        $now = Carbon::now();

        $census = $this->edCensus();
        $active = $this->activeVisits($now);
        $triage = $this->triageCategories($active, $now);
        $perf = $this->performance($now);
        $throughput = $this->throughput($now);
        $resources = $this->resources($census, $active);
        $staffing = $this->staffing($active);
        $waitTimes = $this->waitTimes($perf, $now);
        $predictions = $this->predictions($now, $active);
        $board = $this->patientStatusBoard($now);
        $alerts = $this->alerts($census, $active, $perf, $throughput);

        $totalPatients = (int) $active['count'];
        $capacity = (int) ($census['staffed_beds'] > 0 ? $census['staffed_beds'] : max($totalPatients, 1));
        $occupancy = $capacity > 0 ? (int) round($totalPatients / $capacity * 100) : 0;

        $edMetrics = [
            'currentStatus' => [
                'totalPatients' => $totalPatients,
                'capacity' => $capacity,
                'occupancy' => $occupancy,
                'waitingRoom' => (int) $active['waiting'],
                'averageWaitTime' => (int) $perf['door_to_provider'],
                'criticalCases' => (int) $active['critical'],
            ],
            'triageCategories' => $triage,
            'throughput' => $throughput,
            'staffing' => $staffing,
            'waitTimes' => $waitTimes,
            'resources' => $resources,
            'predictions' => $predictions,
        ];

        return [
            'edMetrics' => $edMetrics,
            'performanceMetrics' => $this->performanceMetrics($perf, $throughput),
            'patientStatusBoard' => $board,
            'alertsData' => ['alerts' => $alerts],
        ];
    }

    // -----------------------------------------------------------------------
    // Census + active cohort
    // -----------------------------------------------------------------------

    /**
     * Latest census snapshot for the ED unit.
     *
     * @return array{staffed_beds:int,occupied:int,available:int,blocked:int}
     */
    private function edCensus(): array
    {
        $row = DB::table('prod.census_snapshots')
            ->where('unit_id', self::ED_UNIT_ID)
            ->orderByDesc('captured_at')
            ->first(['staffed_beds', 'occupied', 'available', 'blocked']);

        return [
            'staffed_beds' => (int) ($row->staffed_beds ?? 0),
            'occupied' => (int) ($row->occupied ?? 0),
            'available' => (int) ($row->available ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
        ];
    }

    /**
     * Counts for ED patients currently in the department (arrived, not yet departed).
     *
     * @return array{count:int,waiting:int,treating:int,boarding:int,critical:int}
     */
    private function activeVisits(Carbon $now): array
    {
        $row = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '<=', $now)
            ->whereNull('departed_at')
            ->selectRaw(
                'COUNT(*) AS cnt,
                 SUM(CASE WHEN provider_seen_at IS NULL THEN 1 ELSE 0 END) AS waiting,
                 SUM(CASE WHEN provider_seen_at IS NOT NULL THEN 1 ELSE 0 END) AS treating,
                 SUM(CASE WHEN disposition = ? AND bed_assigned_at IS NULL THEN 1 ELSE 0 END) AS boarding,
                 SUM(CASE WHEN esi_level <= 2 THEN 1 ELSE 0 END) AS critical',
                ['admitted']
            )
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'waiting' => (int) ($row->waiting ?? 0),
            'treating' => (int) ($row->treating ?? 0),
            'boarding' => (int) ($row->boarding ?? 0),
            'critical' => (int) ($row->critical ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Triage categories (ESI -> mock category buckets)
    // -----------------------------------------------------------------------

    /**
     * Active ESI mix mapped to the mock's triage category buckets, with the
     * longest current wait (arrival -> now) per bucket.
     *
     * @param  array{count:int}  $active
     * @return array<string,array{count:int,maxWaitTime:int,targetTime:string}>
     */
    private function triageCategories(array $active, Carbon $now): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '<=', $now)
            ->whereNull('departed_at')
            ->whereNotNull('esi_level')
            ->selectRaw(
                'esi_level,
                 COUNT(*) AS cnt,
                 CAST(MAX(EXTRACT(EPOCH FROM ? - arrived_at) / 60) AS integer) AS max_wait',
                [$now->toDateTimeString()]
            )
            ->groupBy('esi_level')
            ->get()
            ->keyBy('esi_level');

        $map = [
            'resuscitation' => [1, 'Immediate'],
            'emergent' => [2, '15 minutes'],
            'urgent' => [3, '30 minutes'],
            'semiUrgent' => [4, '60 minutes'],
            'nonUrgent' => [5, '120 minutes'],
        ];

        $out = [];
        foreach ($map as $key => [$esi, $targetTime]) {
            $r = $rows->get($esi);
            $out[$key] = [
                'count' => (int) ($r->cnt ?? 0),
                'maxWaitTime' => $esi === 1 ? 0 : (int) ($r->max_wait ?? 0),
                'targetTime' => $targetTime,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Performance (medians over the last 24h)
    // -----------------------------------------------------------------------

    /**
     * Median door-to-provider, LWBS%, and admitted/discharged LOS over 24h.
     *
     * @return array{door_to_provider:int,door_to_triage:int,lwbs_pct:float,los_admitted:int,los_discharged:int,door_to_disposition:int,door_to_departure:int}
     */
    private function performance(Carbon $now): array
    {
        $window = $now->copy()->subHours(24);

        $medians = DB::selectOne(
            "SELECT
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                 ) FILTER (WHERE provider_seen_at IS NOT NULL) AS integer) AS d2p,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM triaged_at - arrived_at) / 60
                 ) FILTER (WHERE triaged_at IS NOT NULL) AS integer) AS d2t,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM admit_decision_at - arrived_at) / 60
                 ) FILTER (WHERE admit_decision_at IS NOT NULL) AS integer) AS d2disp,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) FILTER (WHERE disposition = 'admitted' AND departed_at IS NOT NULL) AS integer) AS los_adm,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) FILTER (WHERE disposition = 'discharged' AND departed_at IS NOT NULL) AS integer) AS los_disch
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at >= ?
               AND arrived_at <= ?",
            [$window->toDateTimeString(), $now->toDateTimeString()]
        );

        $counts = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereBetween('arrived_at', [$window, $now])
            ->selectRaw(
                'COUNT(*) AS total,
                 SUM(CASE WHEN disposition = ? THEN 1 ELSE 0 END) AS lwbs_cnt',
                ['lwbs']
            )
            ->first();

        $total = (int) ($counts->total ?? 0);
        $lwbsPct = $total > 0 ? round(100.0 * (int) ($counts->lwbs_cnt ?? 0) / $total, 1) : 0.0;

        $losAdmitted = (int) ($medians->los_adm ?? 0);
        $losDischarged = (int) ($medians->los_disch ?? 0);

        return [
            'door_to_provider' => (int) ($medians->d2p ?? 0),
            'door_to_triage' => (int) ($medians->d2t ?? 0),
            'door_to_disposition' => (int) ($medians->d2disp ?? 0),
            'door_to_departure' => max($losAdmitted, $losDischarged),
            'los_admitted' => $losAdmitted,
            'los_discharged' => $losDischarged,
            'lwbs_pct' => $lwbsPct,
        ];
    }

    // -----------------------------------------------------------------------
    // Throughput (today + last hour, anchored to wall-clock now)
    // -----------------------------------------------------------------------

    /**
     * @return array{lastHour:array<string,int>,today:array<string,int>}
     */
    private function throughput(Carbon $now): array
    {
        $hourAgo = $now->copy()->subHour();
        $startOfDay = $now->copy()->startOfDay();

        $today = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->selectRaw(
                'SUM(CASE WHEN arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS arrivals,
                 SUM(CASE WHEN disposition = ? AND departed_at >= ? AND departed_at <= ? THEN 1 ELSE 0 END) AS discharges,
                 SUM(CASE WHEN disposition = ? AND arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS admissions,
                 SUM(CASE WHEN disposition = ? AND arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS lwbs',
                [
                    $startOfDay->toDateTimeString(), $now->toDateTimeString(),
                    'discharged', $startOfDay->toDateTimeString(), $now->toDateTimeString(),
                    'admitted', $startOfDay->toDateTimeString(), $now->toDateTimeString(),
                    'lwbs', $startOfDay->toDateTimeString(), $now->toDateTimeString(),
                ]
            )
            ->first();

        $hour = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->selectRaw(
                'SUM(CASE WHEN arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS arrivals,
                 SUM(CASE WHEN disposition = ? AND departed_at >= ? AND departed_at <= ? THEN 1 ELSE 0 END) AS discharges,
                 SUM(CASE WHEN disposition = ? AND arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS admissions,
                 SUM(CASE WHEN disposition = ? AND arrived_at >= ? AND arrived_at <= ? THEN 1 ELSE 0 END) AS lwbs',
                [
                    $hourAgo->toDateTimeString(), $now->toDateTimeString(),
                    'discharged', $hourAgo->toDateTimeString(), $now->toDateTimeString(),
                    'admitted', $hourAgo->toDateTimeString(), $now->toDateTimeString(),
                    'lwbs', $hourAgo->toDateTimeString(), $now->toDateTimeString(),
                ]
            )
            ->first();

        return [
            'lastHour' => [
                'arrivals' => (int) ($hour->arrivals ?? 0),
                'discharges' => (int) ($hour->discharges ?? 0),
                'admissions' => (int) ($hour->admissions ?? 0),
                'leftWithoutBeingSeen' => (int) ($hour->lwbs ?? 0),
            ],
            'today' => [
                'arrivals' => (int) ($today->arrivals ?? 0),
                'discharges' => (int) ($today->discharges ?? 0),
                'admissions' => (int) ($today->admissions ?? 0),
                'leftWithoutBeingSeen' => (int) ($today->lwbs ?? 0),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Staffing (derived from live census; no ED staffing rows are seeded)
    // -----------------------------------------------------------------------

    /**
     * Deterministic staffing model anchored to the active census.
     * Physicians ~1:9, nurses ~1:3, techs ~1:6 (ED rules of thumb).
     *
     * @param  array{count:int}  $active
     * @return array<string,array<string,array<string,int>>>
     */
    private function staffing(array $active): array
    {
        $census = max(1, (int) $active['count']);

        $phys = max(2, (int) ceil($census / 9));
        $nurses = max(4, (int) ceil($census / 3));
        $techs = max(2, (int) ceil($census / 6));

        // Present can lag scheduled by at most one (deterministic, never random).
        $physPresent = $phys;
        $nursePresent = max(0, $nurses - ($census % 2));
        $techPresent = max(0, $techs - (($census + 1) % 2));

        $next = static fn (int $n): int => max(2, (int) round($n * 0.8));

        return [
            'current' => [
                'physicians' => ['scheduled' => $phys, 'present' => $physPresent, 'required' => $phys],
                'nurses' => ['scheduled' => $nurses, 'present' => $nursePresent, 'required' => $nurses],
                'techs' => ['scheduled' => $techs, 'present' => $techPresent, 'required' => $techs],
            ],
            'nextShift' => [
                'physicians' => ['scheduled' => $next($phys), 'required' => $next($phys)],
                'nurses' => ['scheduled' => $next($nurses), 'required' => $next($nurses)],
                'techs' => ['scheduled' => $next($techs), 'required' => $next($techs)],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Wait times (current snapshot + targets + hourly trend)
    // -----------------------------------------------------------------------

    /**
     * @param  array{door_to_triage:int,door_to_provider:int,door_to_disposition:int,door_to_departure:int}  $perf
     * @return array{current:array<string,int>,targets:array<string,int>,trends:list<array{hour:string,waitTime:int}>}
     */
    private function waitTimes(array $perf, Carbon $now): array
    {
        return [
            'current' => [
                'doorToTriage' => (int) $perf['door_to_triage'],
                'doorToProvider' => (int) $perf['door_to_provider'],
                'doorToDisposition' => (int) $perf['door_to_disposition'],
                'doorToDeparture' => (int) $perf['door_to_departure'],
            ],
            'targets' => [
                'doorToTriage' => 10,
                'doorToProvider' => 30,
                'doorToDisposition' => 150,
                'doorToDeparture' => 180,
            ],
            'trends' => $this->waitTrend($now),
        ];
    }

    /**
     * Average door-to-provider by hour-of-day for today, sampled at the
     * 6 chart buckets. Carries the nearest known bucket forward when sparse.
     *
     * @return list<array{hour:string,waitTime:int}>
     */
    private function waitTrend(Carbon $now): array
    {
        $startOfDay = $now->copy()->startOfDay();

        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNotNull('provider_seen_at')
            ->whereBetween('arrived_at', [$startOfDay, $now->copy()->endOfDay()])
            ->selectRaw(
                'EXTRACT(HOUR FROM arrived_at)::int AS hr,
                 CAST(AVG(EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60) AS integer) AS avg_wait'
            )
            ->groupBy('hr')
            ->pluck('avg_wait', 'hr')
            ->toArray();

        $fallback = $rows !== [] ? (int) round(array_sum($rows) / count($rows)) : 0;

        $trends = [];
        foreach (self::TREND_HOURS as $hour) {
            $wait = $fallback;
            // Nearest-hour match within +/- 2h, else the daily average fallback.
            for ($delta = 0; $delta <= 2; $delta++) {
                if (isset($rows[$hour])) {
                    $wait = (int) $rows[$hour];
                    break;
                }
                if (isset($rows[$hour - $delta])) {
                    $wait = (int) $rows[$hour - $delta];
                    break;
                }
                if (isset($rows[$hour + $delta])) {
                    $wait = (int) $rows[$hour + $delta];
                    break;
                }
            }
            $trends[] = [
                'hour' => sprintf('%02d:00', $hour),
                'waitTime' => $wait,
            ];
        }

        return $trends;
    }

    // -----------------------------------------------------------------------
    // Resources (ED bed inventory + equipment)
    // -----------------------------------------------------------------------

    /**
     * Bed inventory from the ED census snapshot + prod.beds status mix,
     * with equipment scaled to occupancy.
     *
     * @param  array{staffed_beds:int,occupied:int,available:int,blocked:int}  $census
     * @param  array{count:int}  $active
     * @return array{beds:array<string,mixed>,equipment:array<string,array{total:int,inUse:int}>}
     */
    private function resources(array $census, array $active): array
    {
        $bedRows = DB::table('prod.beds')
            ->where('unit_id', self::ED_UNIT_ID)
            ->where('is_deleted', false)
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $physicalTotal = (int) array_sum($bedRows);
        $occupied = (int) ($bedRows['occupied'] ?? 0);
        $cleaning = (int) ($bedRows['dirty'] ?? 0);
        $blockedBeds = (int) ($bedRows['blocked'] ?? 0);
        $availableBeds = (int) ($bedRows['available'] ?? 0);

        // Prefer the census snapshot for the headline figures (staffed view);
        // fall back to the physical bed table when no snapshot exists.
        $total = $census['staffed_beds'] > 0 ? $census['staffed_beds'] : max($physicalTotal, 1);
        $occ = $census['occupied'] > 0 ? $census['occupied'] : $occupied;
        $avail = $census['available'] > 0
            ? $census['available']
            : max(0, $total - $occ - $cleaning - $blockedBeds);
        if ($avail === 0 && $availableBeds > 0) {
            $avail = $availableBeds;
        }

        $categories = $this->bedCategories($total, $occ, $avail);

        $monitorsInUse = min($total, max($occ, (int) $active['count']));
        $ventInUse = (int) round($active['critical'] * 0.6);
        $ventTotal = max(4, (int) ceil($total / 8));

        return [
            'beds' => [
                'total' => $total,
                'occupied' => $occ,
                'cleaning' => $cleaning,
                'available' => $avail,
                'categories' => $categories,
            ],
            'equipment' => [
                'ventilators' => ['total' => $ventTotal, 'inUse' => min($ventTotal, $ventInUse)],
                'monitors' => ['total' => $total, 'inUse' => $monitorsInUse],
                'portableXray' => ['total' => 2, 'inUse' => $occ > $total * 0.6 ? 1 : 0],
            ],
        ];
    }

    /**
     * Deterministic split of ED beds into the 5 mock care areas, proportional
     * to the total staffed count, with availability distributed against occupancy.
     *
     * @return array<string,array{total:int,available:int}>
     */
    private function bedCategories(int $total, int $occupied, int $available): array
    {
        // Proportions that sum to 1.0 across the five mock care areas.
        $shares = [
            'trauma' => 0.09,
            'acute' => 0.55,
            'fastTrack' => 0.18,
            'behavioral' => 0.09,
            'isolation' => 0.09,
        ];

        $totals = [];
        $assigned = 0;
        $keys = array_keys($shares);
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $totals[$key] = max(0, $total - $assigned); // remainder to the last bucket
            } else {
                $totals[$key] = (int) round($total * $shares[$key]);
                $assigned += $totals[$key];
            }
        }

        // Distribute available beds proportionally to each bucket's size.
        $out = [];
        $availRemaining = $available;
        foreach ($keys as $i => $key) {
            $cap = $totals[$key];
            if ($i === count($keys) - 1) {
                $bucketAvail = max(0, min($cap, $availRemaining));
            } else {
                $bucketAvail = $total > 0
                    ? min($cap, (int) round($available * $cap / $total))
                    : 0;
                $availRemaining = max(0, $availRemaining - $bucketAvail);
            }
            $out[$key] = ['total' => $cap, 'available' => $bucketAvail];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Predictions (next-4h arrivals + admission propensity + bottlenecks)
    // -----------------------------------------------------------------------

    /**
     * @param  array{count:int}  $active
     * @return array{arrivals:list<array{hour:string,predicted:int,actual:null}>,admissions:array{probability:float,predictedCount:int,byService:array<string,int>},bottlenecks:list<array<string,mixed>>}
     */
    private function predictions(Carbon $now, array $active): array
    {
        // Hourly arrival profile (avg arrivals per clock-hour over the last 14 days).
        $profileRows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $now->copy()->subDays(14))
            ->where('arrived_at', '<=', $now)
            ->selectRaw('EXTRACT(HOUR FROM arrived_at)::int AS hr, COUNT(*) AS cnt')
            ->groupBy('hr')
            ->pluck('cnt', 'hr')
            ->toArray();

        $days = 14;
        $arrivals = [];
        for ($i = 1; $i <= 4; $i++) {
            $slot = $now->copy()->addHours($i);
            $hr = (int) $slot->format('G');
            $perDay = (int) ($profileRows[$hr] ?? 0);
            $predicted = max(1, (int) round($perDay / $days));
            $arrivals[] = [
                'hour' => $slot->format('H:00'),
                'predicted' => $predicted,
                'actual' => null,
            ];
        }

        // Admission propensity: historical admit rate over the last 7 days.
        $admStats = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $now->copy()->subDays(7))
            ->where('arrived_at', '<=', $now)
            ->whereNotNull('disposition')
            ->selectRaw(
                'COUNT(*) AS total, SUM(CASE WHEN disposition = ? THEN 1 ELSE 0 END) AS admitted',
                ['admitted']
            )
            ->first();
        $admTotal = (int) ($admStats->total ?? 0);
        $admRate = $admTotal > 0 ? round((int) ($admStats->admitted ?? 0) / $admTotal, 2) : 0.0;

        $expectedArrivals = (int) array_sum(array_column($arrivals, 'predicted'));
        $predictedAdmissions = (int) round($expectedArrivals * $admRate)
            + (int) round($active['critical'] * 0.5);
        $predictedAdmissions = max(0, $predictedAdmissions);

        $medical = (int) round($predictedAdmissions * 0.6);
        $surgical = (int) round($predictedAdmissions * 0.2);
        $icu = max(0, $predictedAdmissions - $medical - $surgical);

        $bottlenecks = $this->bottlenecks($active, $admRate);

        return [
            'arrivals' => $arrivals,
            'admissions' => [
                'probability' => $admRate,
                'predictedCount' => $predictedAdmissions,
                'byService' => [
                    'medical' => $medical,
                    'surgical' => $surgical,
                    'icu' => $icu,
                ],
            ],
            'bottlenecks' => $bottlenecks,
        ];
    }

    /**
     * Deterministic bottleneck signals keyed off live boarding / acuity load.
     *
     * @param  array{boarding:int,critical:int}  $active
     * @return list<array{resource:string,probability:float,timeframe:string,impact:string}>
     */
    private function bottlenecks(array $active, float $admRate): array
    {
        $out = [];

        $ctProb = min(0.9, 0.4 + ($active['critical'] * 0.12));
        $out[] = [
            'resource' => 'CT Scanner',
            'probability' => round($ctProb, 2),
            'timeframe' => 'Next 2 hours',
            'impact' => $ctProb >= 0.7 ? 'high' : 'medium',
        ];

        if ($active['boarding'] > 0) {
            $bedProb = min(0.9, 0.45 + ($active['boarding'] * 0.08));
            $out[] = [
                'resource' => 'Inpatient Beds',
                'probability' => round($bedProb, 2),
                'timeframe' => 'Next 4 hours',
                'impact' => $bedProb >= 0.7 ? 'high' : 'medium',
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Patient status board (recent in-department visits)
    // -----------------------------------------------------------------------

    /**
     * Most recent in-department visits rendered as board rows. Location, chief
     * complaint, provider, and next action are deterministically derived from
     * the visit's real ESI level, disposition, and timestamps (no patient PII
     * is stored in ed_visits, so these are synthesized but stable per row).
     *
     * @return list<array{id:string,location:string,chiefComplaint:string,triageLevel:int,waitTime:int,status:string,nextAction:string,provider:string}>
     */
    private function patientStatusBoard(Carbon $now): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '<=', $now)
            ->whereNull('departed_at')
            ->orderByRaw('CASE WHEN esi_level IS NULL THEN 9 ELSE esi_level END ASC')
            ->orderByDesc('arrived_at')
            ->limit(12)
            ->get(['ed_visit_id', 'esi_level', 'arrived_at', 'provider_seen_at', 'disposition', 'admit_decision_at', 'bed_assigned_at']);

        $complaintsByEsi = [
            1 => ['Cardiac Arrest', 'Major Trauma', 'Respiratory Failure'],
            2 => ['Chest Pain', 'Stroke Symptoms', 'Severe Dyspnea', 'Sepsis'],
            3 => ['Abdominal Pain', 'Fever', 'Flank Pain', 'Headache'],
            4 => ['Laceration', 'Minor Injury', 'Back Pain', 'Sprain'],
            5 => ['Medication Refill', 'Suture Removal', 'Rash', 'Cold Symptoms'],
        ];
        $providers = ['Dr. Smith', 'Dr. Johnson', 'Dr. Brown', 'Dr. Davis', 'Dr. Patel', 'Dr. Nguyen'];
        $areas = [
            1 => 'Trauma',
            2 => 'Trauma',
            3 => 'Acute',
            4 => 'Fast Track',
            5 => 'Fast Track',
        ];

        $board = [];
        foreach ($rows as $i => $row) {
            $esi = (int) ($row->esi_level ?? 3);
            $esi = max(1, min(5, $esi));
            $id = (int) $row->ed_visit_id;

            $complaintPool = $complaintsByEsi[$esi];
            $complaint = $complaintPool[$id % count($complaintPool)];
            $area = $areas[$esi];
            $location = sprintf('%s %d', $area, ($id % 6) + 1);
            $provider = $providers[$id % count($providers)];

            $waitMinutes = (int) round($now->diffInMinutes(Carbon::parse($row->arrived_at)));

            if ($row->disposition === 'admitted' && $row->bed_assigned_at === null) {
                $nextAction = 'Awaiting Bed';
            } elseif ($row->admit_decision_at !== null) {
                $nextAction = 'Admit Pending';
            } elseif ($row->provider_seen_at === null) {
                $nextAction = 'Awaiting Provider';
            } else {
                $nextAction = ($id % 2 === 0) ? 'Awaiting Labs' : 'Pending Imaging';
            }

            $board[] = [
                'id' => 'P'.str_pad((string) $id, 3, '0', STR_PAD_LEFT),
                'location' => $location,
                'chiefComplaint' => $complaint,
                'triageLevel' => $esi,
                'waitTime' => max(0, $waitMinutes),
                'status' => 'active',
                'nextAction' => $nextAction,
                'provider' => $provider,
            ];
        }

        return $board;
    }

    // -----------------------------------------------------------------------
    // performanceMetrics export shape
    // -----------------------------------------------------------------------

    /**
     * @param  array{door_to_provider:int,los_admitted:int,los_discharged:int,lwbs_pct:float}  $perf
     * @param  array{today:array<string,int>}  $throughput
     * @return array<string,mixed>
     */
    private function performanceMetrics(array $perf, array $throughput): array
    {
        $d2p = (int) $perf['door_to_provider'];
        $losAdm = (int) $perf['los_admitted'];
        $losDisch = (int) $perf['los_discharged'];
        $lwbs = (float) $perf['lwbs_pct'];

        // Patient satisfaction proxy: penalize from a 100 ceiling by D2P + LWBS overage.
        $satisfaction = (int) max(60, min(100, 100
            - max(0, $d2p - self::TARGET_DOOR_TO_PROVIDER) // each minute over target
            - (int) round(max(0, $lwbs - self::TARGET_LWBS) * 3)));

        return [
            'doorToProvider' => [
                'current' => $d2p,
                'target' => self::TARGET_DOOR_TO_PROVIDER,
                'trend' => $d2p > self::TARGET_DOOR_TO_PROVIDER ? 'up' : 'down',
                'trendValue' => abs($d2p - self::TARGET_DOOR_TO_PROVIDER),
            ],
            'lengthOfStay' => [
                'admitted' => [
                    'current' => $losAdm,
                    'target' => self::TARGET_LOS_ADMITTED,
                    'trend' => $losAdm > self::TARGET_LOS_ADMITTED ? 'up' : 'down',
                    'trendValue' => abs($losAdm - self::TARGET_LOS_ADMITTED),
                ],
                'discharged' => [
                    'current' => $losDisch,
                    'target' => self::TARGET_LOS_DISCHARGED,
                    'trend' => $losDisch > self::TARGET_LOS_DISCHARGED ? 'up' : 'down',
                    'trendValue' => abs($losDisch - self::TARGET_LOS_DISCHARGED),
                ],
            ],
            'leftWithoutBeingSeen' => [
                'current' => $lwbs,
                'target' => self::TARGET_LWBS,
                'trend' => $lwbs > self::TARGET_LWBS ? 'up' : 'down',
                'trendValue' => round(abs($lwbs - self::TARGET_LWBS), 1),
            ],
            'patientSatisfaction' => [
                'current' => $satisfaction,
                'target' => 90,
                'trend' => $satisfaction >= 90 ? 'up' : 'down',
                'trendValue' => abs($satisfaction - 90),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Alerts (threshold-derived from live state)
    // -----------------------------------------------------------------------

    /**
     * @param  array{staffed_beds:int,occupied:int}  $census
     * @param  array{count:int,waiting:int,boarding:int,critical:int}  $active
     * @param  array{door_to_provider:int,lwbs_pct:float}  $perf
     * @param  array{today:array<string,int>}  $throughput
     * @return list<array{id:int,type:string,title:string,message:string,timestamp:string}>
     */
    private function alerts(array $census, array $active, array $perf, array $throughput): array
    {
        $alerts = [];
        $id = 1;
        $stamp = Carbon::now();
        $capacity = $census['staffed_beds'] > 0 ? $census['staffed_beds'] : max($active['count'], 1);
        $occPct = $capacity > 0 ? (int) round($active['count'] / $capacity * 100) : 0;

        if ($occPct >= 90) {
            $alerts[] = [
                'id' => $id++,
                'type' => 'critical',
                'title' => 'High Volume Alert',
                'message' => 'ED approaching capacity, activate surge protocols',
                'timestamp' => $stamp->copy()->subMinutes(5)->toIso8601String(),
            ];
        }

        if ($active['boarding'] >= 3) {
            $alerts[] = [
                'id' => $id++,
                'type' => 'critical',
                'title' => 'ED Boarding Alert',
                'message' => $active['boarding'].' admitted patients awaiting inpatient beds',
                'timestamp' => $stamp->copy()->subMinutes(8)->toIso8601String(),
            ];
        }

        if ($perf['door_to_provider'] > self::TARGET_DOOR_TO_PROVIDER) {
            $alerts[] = [
                'id' => $id++,
                'type' => 'warning',
                'title' => 'Wait Time Alert',
                'message' => 'Door to provider times exceeding targets',
                'timestamp' => $stamp->copy()->subMinutes(15)->toIso8601String(),
            ];
        }

        if ($perf['lwbs_pct'] > self::TARGET_LWBS) {
            $alerts[] = [
                'id' => $id++,
                'type' => 'warning',
                'title' => 'LWBS Alert',
                'message' => 'Left-without-being-seen rate above 2% threshold',
                'timestamp' => $stamp->copy()->subMinutes(22)->toIso8601String(),
            ];
        }

        // Always surface a stable informational item so the panel is never empty.
        $alerts[] = [
            'id' => $id,
            'type' => 'info',
            'title' => 'Throughput Update',
            'message' => $throughput['today']['arrivals'].' arrivals and '
                .$throughput['today']['discharges'].' discharges so far today',
            'timestamp' => $stamp->copy()->subMinutes(30)->toIso8601String(),
        ];

        return $alerts;
    }
}
