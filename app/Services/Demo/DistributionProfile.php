<?php

namespace App\Services\Demo;

use RuntimeException;

/**
 * Typed accessor for a facility's VERIFIED distribution profile
 * (config/hospital/hospital-1-distributions.json).
 *
 * This JSON is a rigorously derived, independently reproduced statistical
 * profile of the synthetic MIMIC-IV / atlantic_health cohort — admission
 * archetypes, careunit transition matrix, per-unit-type LOS, ICU LOS, ED
 * throughput, service-line weights, disposition mix, geriatric demographics,
 * and the acuity/procedure signature. Until this class existed it was cited
 * only in a code COMMENT and never loaded at runtime, so the operational
 * seeders fell back to hand-tuned constants and the data came out coherent but
 * not distribution-true (flat per-unit occupancy, an over-acute ESI mix, a
 * fantasy discharge-before-noon rate). See
 * docs/DEMO-DATA-COHERENCE-FEEDBACK-AND-PLAN-2026-07-10.md §3.5 / §4.
 *
 * Two kinds of data are exposed:
 *   1. SOURCE-DERIVED shapes read straight from the JSON (losByUnitType, esi
 *      is NOT in the JSON, dispositionMix, transitionMatrix, …).
 *   2. CLINICAL-BENCHMARK bands from config('hospital.plausibility_targets') —
 *      realistic operating ranges the source cohort doesn't pin down (occupancy
 *      by unit type, ESI pyramid, transport mix, discharge-before-noon).
 *
 * Mirrors App\Support\Hospital\HospitalManifest: facility-parameterized, loaded
 * once, statically cached per facility_key.
 */
final class DistributionProfile
{
    /** @var array<string,array<string,mixed>> keyed by facility_key */
    private static array $cache = [];

    private readonly string $facilityKey;

    public function __construct(?string $facilityKey = null)
    {
        $this->facilityKey = $facilityKey
            ?? (string) config('hospital.default_facility', 'SUMMIT_REGIONAL');
    }

    public static function forFacility(string $facilityKey): self
    {
        return new self($facilityKey);
    }

    public function facilityKey(): string
    {
        return $this->facilityKey;
    }

    // ---- identity / capacity vocabulary (plan §8.1) ----

    /** Licensed inpatient beds (Summit = 500). The only house denominator basis. */
    public function licensedInpatientBeds(): int
    {
        return (int) ($this->data()['facility']['licensedBeds'] ?? 0);
    }

    /** prod.units.type values that roll into the inpatient house denominator. */
    public function inpatientUnitTypes(): array
    {
        return (array) config('hospital.inpatient_unit_types', ['icu', 'step_down', 'med_surg']);
    }

    // ---- source-derived shapes (read straight from the JSON) ----

    /** @return list<array<string,mixed>> the 23-unit CAD roster + ED + PERIOP. */
    public function unitRoster(): array
    {
        return (array) ($this->data()['unitRoster'] ?? []);
    }

    /** Length-of-stay stats keyed by careunit unitType (median/p75/p90/max/mean days). */
    public function losByUnitType(): array
    {
        $out = [];
        foreach ((array) ($this->data()['losDistributionsByUnitType'] ?? []) as $row) {
            $out[(string) $row['unitType']] = $row;
        }

        return $out;
    }

    /**
     * True ICU bed LOS (icustays.los, median 4.07d) — NOT the sub-1-day MICU
     * "transport unit" artifact in losByUnitType. The JSON flags this trap
     * explicitly; use this for MICU3/SICU3/NSICU3/CVICU3/BURN3.
     */
    public function icuLos(): array
    {
        return (array) ($this->data()['icuLos'] ?? []);
    }

    /** ED door-to-disposition throughput hours (median 5.38h, p90 10.3h). */
    public function edThroughputHours(): array
    {
        return (array) ($this->data()['edThroughputHours'] ?? []);
    }

    /** @return list<array{destination:string,probability:float}> verified disposition mix. */
    public function dispositionMix(): array
    {
        return (array) ($this->data()['dispositionMix'] ?? []);
    }

    /** @return list<array{path:string,probability:float}> */
    public function admissionArchetypes(): array
    {
        return (array) ($this->data()['admissionArchetypes'] ?? []);
    }

    /** @return list<array{from:string,to:string,probability:float}> careunit transitions. */
    public function transitionMatrix(): array
    {
        return (array) ($this->data()['transitionMatrix'] ?? []);
    }

    /** @return list<array<string,mixed>> service-line weights (sum to 1.0). */
    public function serviceLineWeights(): array
    {
        return (array) ($this->data()['serviceLineWeights'] ?? []);
    }

    public function demographics(): array
    {
        return (array) ($this->data()['demographics'] ?? []);
    }

    public function mortalityRate(): float
    {
        return (float) ($this->data()['mortalityRate'] ?? 0.0);
    }

    // ---- clinical-benchmark plausibility bands (config-driven; tuneable) ----

    /** @return array<string,array{0:float,1:float}> unit_type => [minPct, maxPct] as fractions. */
    public function occupancyBands(): array
    {
        return (array) config('hospital.plausibility_targets.occupancy_by_unit_type', []);
    }

    /** @return array<int,array{0:float,1:float}> esi level => [minShare, maxShare]. */
    public function esiBands(): array
    {
        return (array) config('hospital.plausibility_targets.esi_share', []);
    }

    /** @return array{0:float,1:float} [min, max] share discharged before noon. */
    public function dischargeBeforeNoonBand(): array
    {
        return (array) config('hospital.plausibility_targets.discharge_before_noon', [0.18, 0.42]);
    }

    /** @return array<string,array{0:float,1:float}> priority => [minShare, maxShare]. */
    public function transportPriorityBands(): array
    {
        return (array) config('hospital.plausibility_targets.transport_priority_share', []);
    }

    public function transportOverdueShareMax(): float
    {
        return (float) config('hospital.plausibility_targets.transport_overdue_share_max', 0.20);
    }

    /** @return array{0:float,1:float} [min, max] cases per physical OR room per weekday. */
    public function orCasesPerRoomWeekdayBand(): array
    {
        return (array) config('hospital.plausibility_targets.or_cases_per_room_weekday', [3.0, 12.0]);
    }

    // ---- loading (mirrors HospitalManifest) ----

    /** @return array<string,mixed> */
    private function data(): array
    {
        if (! isset(self::$cache[$this->facilityKey])) {
            $path = base_path($this->configPath());
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException("Distribution profile file not readable: {$path}");
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Distribution profile is not valid JSON: {$path}");
            }
            self::$cache[$this->facilityKey] = $decoded;
        }

        return self::$cache[$this->facilityKey];
    }

    private function configPath(): string
    {
        /** @var array<string,string> $map */
        $map = (array) config('hospital.distributions', []);
        $path = $map[$this->facilityKey] ?? null;
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                "No distribution profile registered for facility_key '{$this->facilityKey}'. ".
                'Register it under config/hospital.php `distributions`.'
            );
        }

        return $path;
    }

    /** Test helper: drop the static cache. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
