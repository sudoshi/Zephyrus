<?php

namespace App\Services\Rtdc;

use App\Services\Ancillary\AncillaryReadinessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Discharge Priorities payload (resources/js/Pages/RTDC/DischargePriorities.jsx)
 * from the live `prod` schema (DB_SCHEMA=prod).
 *
 * The returned array reproduces — exactly — the shape that
 * resources/js/utils/generateMockDischargeData.js exported:
 *
 *   priority1, priority2, priority3, priority4 : list of patient objects with
 *     { id, name, age, hospital, unit, service, los, expectedLos,
 *       unitCapacity (string "NN%"), improvement (Rapid|Steady|Slow),
 *       risk (Low|Medium|High), priority (1..4) }
 *   hospitals, units, services : string[] (filter option lists)
 *
 * Tiering rationale (mirrors the four PrioritySection descriptions on the page):
 *   P1 — units with demand/capacity mismatch (occupancy >= 90%) AND patient
 *        showing discharge readiness (LOS >= GMLOS, no open barriers).
 *   P2 — balanced units (occupancy < 90%) with rapid improvement.
 *   P3 — units approaching or above 80% capacity (remaining cases there).
 *   P4 — everyone else; reevaluate after 2 PM.
 *
 * Computation is deterministic and safe on empty tables: every accessor
 * returns empty collections rather than throwing. Names are derived
 * deterministically from patient_ref so the surface looks identical to the
 * mock while reflecting the live roster.
 */
class DischargePrioritiesService
{
    public function __construct(private readonly AncillaryReadinessService $readiness) {}

    /** Per-tier display caps — keep the four scroll columns visually balanced. */
    private const TIER_CAPS = [1 => 18, 2 => 14, 3 => 14, 4 => 8];

    /** GMLOS fallback (days) when a unit type has no reference row. */
    private const GMLOS_FALLBACK = 4.0;

    /** Surname pool (matches generateMockDischargeData.js). */
    private const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones',
        'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
        'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
    ];

    /** Given-name pool (matches generateMockDischargeData.js). */
    private const FIRST_NAMES = [
        'James', 'John', 'Robert', 'Michael', 'William',
        'David', 'Richard', 'Joseph', 'Thomas', 'Charles',
        'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth',
        'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen',
    ];

    /** Clinical service inferred from unit type (drives the Service filter). */
    private const TYPE_SERVICE = [
        'icu' => 'Critical Care',
        'step_down' => 'Cardiology',
        'med_surg' => 'Internal Medicine',
    ];

    /**
     * Full Discharge Priorities payload.
     *
     * @return array{
     *     priority1: list<array<string,mixed>>,
     *     priority2: list<array<string,mixed>>,
     *     priority3: list<array<string,mixed>>,
     *     priority4: list<array<string,mixed>>,
     *     hospitals: list<string>,
     *     units: list<string>,
     *     services: list<string>
     * }
     */
    public function build(): array
    {
        $hospital = (string) config('facility_models.zep_500.facility_name', 'Summit Regional Medical Center');

        $occupancyByUnit = $this->occupancyByUnit();
        $gmlosByType = $this->gmlosByType();
        $barriersByEncounter = $this->openBarrierCountByEncounter();

        $tiers = [1 => [], 2 => [], 3 => [], 4 => []];
        $unitOptions = [];
        $serviceOptions = [];
        $encounters = $this->activeInpatientEncounters();
        $imagingByEncounter = $this->readiness->imagingForEncounters(
            collect($encounters)->pluck('encounter_id')->map(fn (mixed $id): int => (int) $id)->all(),
            'rtdc',
        );
        $labByEncounter = $this->readiness->laboratoryForEncounters(
            collect($encounters)->pluck('encounter_id')->map(fn (mixed $id): int => (int) $id)->all(),
            'rtdc',
        );
        $medByEncounter = $this->readiness->medicationForEncounters(
            collect($encounters)->pluck('encounter_id')->map(fn (mixed $id): int => (int) $id)->all(),
            'rtdc',
        );

        foreach ($encounters as $row) {
            $unitType = (string) $row->unit_type;
            $unitName = (string) $row->unit_name;
            $service = $this->serviceForUnit($unitType, $unitName);

            $unitOptions[$unitName] = true;
            $serviceOptions[$service] = true;

            $los = $this->lengthOfStayDays($row->admitted_at);
            $gmlos = (float) ($gmlosByType[$unitType] ?? self::GMLOS_FALLBACK);
            $occupancy = (int) ($occupancyByUnit[(int) $row->unit_id] ?? 0);
            $barriers = (int) ($barriersByEncounter[(int) $row->encounter_id] ?? 0);
            $acuity = (int) ($row->acuity_tier ?? 2);

            $ratio = $gmlos > 0 ? $los / $gmlos : 1.0;
            $improvement = $this->improvement($ratio, $barriers);
            $risk = $this->risk($improvement, $barriers, $acuity);
            $tier = $this->assignTier($occupancy, $improvement);
            $imaging = $imagingByEncounter->get((int) $row->encounter_id);
            $lab = $labByEncounter->get((int) $row->encounter_id);
            $medication = $medByEncounter->get((int) $row->encounter_id);

            $tiers[$tier][] = [
                'patient' => [
                    'id' => (int) $row->encounter_id,
                    'name' => $this->displayName((string) $row->patient_ref),
                    'age' => $this->displayAge((string) $row->patient_ref),
                    'hospital' => $hospital,
                    'unit' => $unitName,
                    'service' => $service,
                    'los' => (int) round($los),
                    'expectedLos' => $this->expectedLos($row->expected_discharge_date, $row->admitted_at, $gmlos),
                    'unitCapacity' => $occupancy.'%',
                    'improvement' => $improvement,
                    'risk' => $risk,
                    'priority' => $tier,
                    'imaging' => $imaging,
                    'lab' => $lab,
                    'medication' => $medication,
                    'readiness' => collect([$imaging, $lab, $medication])->filter()->values()->all(),
                ],
                'sort' => $ratio,
            ];
        }

        return [
            'priority1' => $this->finalizeTier($tiers[1], 1),
            'priority2' => $this->finalizeTier($tiers[2], 2),
            'priority3' => $this->finalizeTier($tiers[3], 3),
            'priority4' => $this->finalizeTier($tiers[4], 4),
            'hospitals' => [$hospital],
            'units' => array_values(array_map('strval', array_keys($unitOptions))),
            'services' => array_values(array_map('strval', array_keys($serviceOptions))),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Active, non-ED inpatient encounters joined to their unit.
     *
     * @return list<object>
     */
    private function activeInpatientEncounters(): array
    {
        return DB::select(
            "SELECT e.encounter_id, e.patient_ref, e.unit_id, e.admitted_at,
                    e.expected_discharge_date, e.acuity_tier,
                    u.name AS unit_name, u.type AS unit_type
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id
             WHERE e.status = 'active'
               AND e.is_deleted = false
               AND u.is_deleted = false
               AND u.type <> 'ed'
             ORDER BY e.encounter_id"
        );
    }

    /**
     * Latest-snapshot occupancy percentage per unit (DISTINCT ON).
     *
     * @return array<int,int>
     */
    private function occupancyByUnit(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.staffed_beds, cs.occupied
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );

        $out = [];
        foreach ($rows as $row) {
            $staffed = (int) $row->staffed_beds;
            $occupied = (int) $row->occupied;
            $out[(int) $row->unit_id] = $staffed > 0
                ? (int) round($occupied / $staffed * 100)
                : 0;
        }

        return $out;
    }

    /**
     * GMLOS days keyed by unit type.
     *
     * @return array<string,float>
     */
    private function gmlosByType(): array
    {
        return DB::table('prod.gmlos_references')
            ->pluck('gmlos_days', 'unit_type')
            ->map(fn ($v): float => (float) $v)
            ->toArray();
    }

    /**
     * Open-barrier count per encounter (placement / discharge friction).
     *
     * @return array<int,int>
     */
    private function openBarrierCountByEncounter(): array
    {
        return DB::table('prod.barriers')
            ->where('status', 'open')
            ->where('is_deleted', false)
            ->whereNotNull('encounter_id')
            ->selectRaw('encounter_id, COUNT(*) AS cnt')
            ->groupBy('encounter_id')
            ->pluck('cnt', 'encounter_id')
            ->map(fn ($v): int => (int) $v)
            ->toArray();
    }

    // -----------------------------------------------------------------------
    // Derivation helpers
    // -----------------------------------------------------------------------

    /**
     * Sort each tier by readiness (most-ready first), strip the sort key, and
     * cap the list so the four scroll columns stay visually balanced.
     *
     * @param  list<array{patient:array<string,mixed>,sort:float}>  $rows
     * @return list<array<string,mixed>>
     */
    private function finalizeTier(array $rows, int $tier): array
    {
        usort($rows, fn ($a, $b): int => $b['sort'] <=> $a['sort']);

        $cap = self::TIER_CAPS[$tier] ?? 12;

        return array_map(
            fn (array $r): array => $r['patient'],
            array_slice($rows, 0, $cap)
        );
    }

    /** Length of stay in days (>= 0), from admission to now. */
    private function lengthOfStayDays(?string $admittedAt): float
    {
        if ($admittedAt === null) {
            return 0.0;
        }

        $days = Carbon::parse($admittedAt)->diffInHours(Carbon::now()) / 24.0;

        return max(0.0, $days);
    }

    /**
     * Expected LOS in days: prefer the planned admit→expected-discharge span;
     * otherwise fall back to the unit GMLOS.
     */
    private function expectedLos(?string $expectedDischargeDate, ?string $admittedAt, float $gmlos): int
    {
        if ($expectedDischargeDate !== null && $admittedAt !== null) {
            $span = Carbon::parse($admittedAt)->startOfDay()
                ->diffInDays(Carbon::parse($expectedDischargeDate)->startOfDay(), false);
            if ($span > 0) {
                return (int) $span;
            }
        }

        return max(1, (int) round($gmlos));
    }

    /** Rapid / Steady / Slow from readiness ratio and open barriers. */
    private function improvement(float $ratio, int $barriers): string
    {
        if ($barriers > 0) {
            return 'Slow';
        }
        if ($ratio >= 1.25) {
            return 'Rapid';
        }
        if ($ratio >= 1.0) {
            return 'Steady';
        }

        return 'Slow';
    }

    /** Low / Medium / High discharge risk. */
    private function risk(string $improvement, int $barriers, int $acuity): string
    {
        if ($barriers > 0 || $acuity >= 4) {
            return 'High';
        }
        if ($improvement === 'Rapid') {
            return 'Low';
        }
        if ($improvement === 'Steady') {
            return $acuity >= 3 ? 'Medium' : 'Low';
        }

        return 'Medium';
    }

    /** Single best-fit tier (P1 -> P4), evaluated highest-priority first. */
    private function assignTier(int $occupancy, string $improvement): int
    {
        $ready = $improvement === 'Rapid' || $improvement === 'Steady';

        if ($occupancy >= 90 && $ready) {
            return 1;
        }
        if ($occupancy < 90 && $improvement === 'Rapid') {
            return 2;
        }
        if ($occupancy >= 80) {
            return 3;
        }

        return 4;
    }

    private function serviceForUnit(string $unitType, string $unitName): string
    {
        $name = strtolower($unitName);

        if (str_contains($name, 'onc') || str_contains($name, 'hema') || str_contains($name, 'bmt')) {
            return 'Oncology';
        }
        if (str_contains($name, 'neuro')) {
            return 'Neurology';
        }
        if (str_contains($name, 'rehab')) {
            return 'Rehabilitation';
        }
        if (str_contains($name, 'surg') || str_contains($name, 'gyn') || str_contains($name, 'trauma')) {
            return 'General Surgery';
        }
        if (str_contains($name, 'cardio') || str_contains($name, 'cv') || str_contains($name, 'tele')) {
            return 'Cardiology';
        }

        return self::TYPE_SERVICE[$unitType] ?? 'Internal Medicine';
    }

    /** Deterministic display name from a patient_ref. */
    private function displayName(string $patientRef): string
    {
        $hash = crc32($patientRef);
        $first = self::FIRST_NAMES[$hash % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[(int) ($hash / 7) % count(self::LAST_NAMES)];

        return $first.' '.$last;
    }

    /** Deterministic plausible adult age from a patient_ref (25..85). */
    private function displayAge(string $patientRef): int
    {
        return 25 + (crc32($patientRef.'-age') % 61);
    }
}
