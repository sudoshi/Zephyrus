<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Service Huddle payload (per-unit / per-service inpatient roster +
 * metric tiles) from the live `prod` schema.
 *
 * The returned shape reproduces — exactly — what
 * resources/js/Pages/RTDC/ServiceHuddle.jsx consumes from its legacy mock
 * (resources/js/mock-data/rtdc-service-huddle.js → { metrics, patients }).
 * The page only reads a subset of each patient object; we populate that subset
 * plus the two nested keys its children require (careJourney for the journey
 * modal, dischargePlan.estimatedDischargeDate for CareJourneySummary). All
 * derived demographics (name, mrn, age, service, team, nurse, vitals) are
 * stable per encounter_id — prod.encounters stores no PII — so the roster is
 * deterministic across renders.
 *
 * Computation is safe on empty tables: every accessor returns zeros / empty
 * collections rather than throwing.
 */
class ServiceHuddleService
{
    /** ED is an arrival surface, not an inpatient ward roster. */
    private const EXCLUDED_UNIT_TYPE = 'ed';

    /** GMLOS fallback (days) per unit type when no live reference row exists. */
    private const DEFAULT_GMLOS = [
        'icu' => 5.8,
        'step_down' => 3.5,
        'med_surg' => 4.2,
    ];

    /** Clinical service lines (deterministically assigned per encounter). */
    private const SERVICES = [
        'Cardiology', 'Nephrology', 'Neurology', 'Oncology',
        'Internal Medicine', 'Orthopedics', 'Pulmonology', 'General Surgery',
    ];

    private const PRIMARY_TEAMS = [
        'CHF Team', 'Stroke Team', 'Medical Team A', 'Medical Team B',
        'Medical Team C', 'Surgical Team A', 'Surgical Team B', 'Oncology Team',
    ];

    private const CONSULTING_SERVICES = [
        'Infectious Disease', 'Pain Management', 'Psychiatry', 'Physical Therapy',
        'Occupational Therapy', 'Wound Care', 'Nutrition', 'Social Work',
    ];

    private const NURSES = [
        'Sarah Chen, RN', 'Michael Rodriguez, RN', 'Emily Johnson, RN',
        'David Kim, RN', 'Jessica Taylor, RN', 'James Wilson, RN',
        'Maria Garcia, RN', 'Robert Smith, RN', 'Lisa Brown, RN',
        'John Davis, RN', 'Amanda White, RN', 'Kevin Anderson, RN',
    ];

    private const FIRST_NAMES = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael',
        'Linda', 'William', 'Elizabeth', 'David', 'Barbara', 'Richard', 'Susan',
        'Joseph', 'Jessica', 'Thomas', 'Karen', 'Charles', 'Nancy',
    ];

    private const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
        'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson',
        'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee',
    ];

    private const MOBILITY = ['Ambulatory', 'With Assistance', 'Bed Rest'];

    private const CARE_LEVEL = ['Routine', 'Intermediate', 'Critical'];

    private const PHASE = ['Assessment', 'Treatment', 'Recovery'];

    /**
     * Full Service Huddle payload.
     *
     * @return array{
     *     patients: list<array<string,mixed>>,
     *     metrics: array<string,array<string,int>>
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();
        $gmlos = $this->gmlosByUnitType();
        $rows = $this->activeInpatientEncounters();

        $patients = [];
        $critical = 0;
        $guarded = 0;
        $stable = 0;
        $isolation = 0;
        $specialEquipment = 0;

        foreach ($rows as $row) {
            $patient = $this->buildPatient($row, $gmlos, $now);
            $patients[] = $patient;

            match ($patient['status']) {
                'Critical' => $critical++,
                'Guarded' => $guarded++,
                default => $stable++,
            };

            if (! empty($row->isolation_capable)) {
                $isolation++;
            }
            // ICU / step-down occupancy proxies higher-acuity special equipment.
            if (in_array((string) $row->unit_type, ['icu', 'step_down'], true)) {
                $specialEquipment++;
            }
        }

        $total = count($patients);

        return [
            'patients' => $patients,
            'metrics' => [
                'unitMetrics' => $this->unitMetrics($total),
                'careRequirements' => [
                    'criticalCare' => $critical,
                    'telemetry' => $guarded,
                    'isolation' => $isolation,
                    'specialEquipment' => $specialEquipment,
                ],
                'acuityStatus' => [
                    'critical' => $critical,
                    'guarded' => $guarded,
                    'stable' => $stable,
                    'total' => $total,
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Active inpatient encounters (ED excluded) with unit + bed context, in a
     * stable order (unit, then bed label) so the roster groups by ward.
     *
     * @return list<object>
     */
    private function activeInpatientEncounters(): array
    {
        return DB::select(
            "SELECT
                e.encounter_id, e.patient_ref, e.unit_id, e.bed_id,
                e.admitted_at, e.expected_discharge_date, e.acuity_tier,
                u.name AS unit_name, u.type AS unit_type,
                b.label AS bed_label, b.isolation_capable
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id
             LEFT JOIN prod.beds b ON b.bed_id = e.bed_id
             WHERE e.status = 'active'
               AND e.is_deleted = false
               AND u.is_deleted = false
               AND u.type <> ?
             ORDER BY u.name ASC, b.label ASC NULLS LAST, e.encounter_id ASC",
            [self::EXCLUDED_UNIT_TYPE]
        );
    }

    /**
     * GMLOS (days) keyed by unit type, falling back to sane defaults so an
     * empty reference table never produces null discharge dates.
     *
     * @return array<string,float>
     */
    private function gmlosByUnitType(): array
    {
        $byType = self::DEFAULT_GMLOS;

        foreach (DB::table('prod.gmlos_references')->get(['unit_type', 'gmlos_days']) as $ref) {
            $byType[(string) $ref->unit_type] = (float) $ref->gmlos_days;
        }

        return $byType;
    }

    /**
     * Count of pending, non-deleted bed requests (house-wide incoming demand).
     */
    private function pendingAdmissions(): int
    {
        return (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();
    }

    /**
     * Active encounters with an expected discharge of today (house-wide).
     */
    private function expectedDischargesToday(): int
    {
        return (int) DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereDate('expected_discharge_date', Carbon::today())
            ->count();
    }

    // -----------------------------------------------------------------------
    // Patient roster row
    // -----------------------------------------------------------------------

    /**
     * @param  array<string,float>  $gmlos
     * @return array<string,mixed>
     */
    private function buildPatient(object $row, array $gmlos, Carbon $now): array
    {
        $id = (int) $row->encounter_id;
        $seed = abs(crc32((string) ($row->patient_ref ?? $id)));

        $admit = $row->admitted_at ? Carbon::parse($row->admitted_at) : $now->copy();
        $unitType = (string) $row->unit_type;
        $los = (float) ($gmlos[$unitType] ?? 4.0);

        // Estimated discharge: live expected_discharge_date when present, else
        // admit + GMLOS (floor 1 day so CareJourneySummary never divides by 0).
        $estDischarge = $row->expected_discharge_date
            ? Carbon::parse($row->expected_discharge_date)->endOfDay()
            : $admit->copy()->addDays(max(1, (int) ceil($los)));

        $status = $this->statusForTier((int) $row->acuity_tier);

        return [
            'id' => $id,
            'room' => $row->bed_label ? (string) $row->bed_label : 'Unassigned',
            'name' => $this->patientName($seed),
            'mrn' => 'MRN'.str_pad((string) (100000 + ($seed % 900000)), 6, '0', STR_PAD_LEFT),
            'age' => 18 + ($seed % 78),
            'admitDate' => $admit->toIso8601String(),
            'unit' => (string) $row->unit_name,
            'service' => self::SERVICES[$seed % count(self::SERVICES)],
            'status' => $status,
            'vitalSigns' => [
                'bp' => $this->bloodPressure($seed),
                'o2sat' => $this->oxygenSaturation($seed),
            ],
            'primaryTeam' => self::PRIMARY_TEAMS[$seed % count(self::PRIMARY_TEAMS)],
            'consultingServices' => $this->consultingServices($seed),
            'assignedNurse' => self::NURSES[$seed % count(self::NURSES)],
            'careJourney' => [
                'mobility' => self::MOBILITY[$seed % count(self::MOBILITY)],
                'careLevel' => self::CARE_LEVEL[(int) $row->acuity_tier % count(self::CARE_LEVEL)],
                'phase' => self::PHASE[$seed % count(self::PHASE)],
            ],
            'dischargePlan' => [
                'estimatedDischargeDate' => $estDischarge->toIso8601String(),
            ],
        ];
    }

    /**
     * Map acuity tier (1 = highest) to the page's clinical-status vocabulary.
     */
    private function statusForTier(int $tier): string
    {
        return match (max(1, min(4, $tier))) {
            1 => 'Critical',
            2 => 'Guarded',
            default => 'Stable',
        };
    }

    private function patientName(int $seed): string
    {
        $first = self::FIRST_NAMES[$seed % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[intdiv($seed, count(self::FIRST_NAMES)) % count(self::LAST_NAMES)];

        return "{$first} {$last}";
    }

    private function bloodPressure(int $seed): string
    {
        $systolic = 100 + ($seed % 60);
        $diastolic = 60 + ($seed % 30);

        return "{$systolic}/{$diastolic}";
    }

    private function oxygenSaturation(int $seed): string
    {
        $sat = 90 + ($seed % 10);
        $support = ($seed % 4 === 0) ? '2L NC' : 'RA';

        return "{$sat}% {$support}";
    }

    /**
     * 1–3 distinct consulting services, stable per seed.
     *
     * @return list<string>
     */
    private function consultingServices(int $seed): array
    {
        $count = 1 + ($seed % 3);
        $services = [];
        $n = count(self::CONSULTING_SERVICES);
        for ($i = 0; $i < $count; $i++) {
            $service = self::CONSULTING_SERVICES[($seed + $i * 3) % $n];
            if (! in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return $services;
    }

    // -----------------------------------------------------------------------
    // Metric tiles
    // -----------------------------------------------------------------------

    /**
     * @return array{occupancy:int,availableBeds:int,pendingAdmissions:int,expectedDischarges:int}
     */
    private function unitMetrics(int $rosterTotal): array
    {
        $staffedBeds = (int) DB::table('prod.units')
            ->where('is_deleted', false)
            ->where('type', '<>', self::EXCLUDED_UNIT_TYPE)
            ->sum('staffed_bed_count');

        $occupancy = $staffedBeds > 0
            ? (int) round($rosterTotal / $staffedBeds * 100)
            : 0;

        return [
            'occupancy' => min(100, $occupancy),
            'availableBeds' => max(0, $staffedBeds - $rosterTotal),
            'pendingAdmissions' => $this->pendingAdmissions(),
            'expectedDischarges' => $this->expectedDischargesToday(),
        ];
    }
}
