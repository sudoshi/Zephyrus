<?php

namespace Database\Seeders;

use App\Services\Demo\OperationalDemoDataService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo tuning — nudges the seeded `prod` schema into the compelling, plausible *live* state the
 * Summit Healthcare mobile demo needs, on top of whatever the base seeders produced:
 *
 *   1. ED bed inventory == staffed_bed_count (prune phantom "available" ED beds).
 *   2. Reconcile stray active encounters (occupied beds == active encounters, per unit).
 *   3. ~85% inpatient occupancy (mark beds occupied + create matching active encounters).
 *   4. Near-now transport / EVS / staffing SLAs (so nothing reads days-overdue).
 *   5. Today's staffing plans with realistic gaps (MICU critical, SICU/6E/7E gap).
 *   6. Varied OR surgeons across the anchor day (so the live board isn't one surgeon).
 *
 * Idempotent and safe to re-run: occupancy/reconcile are no-ops once at target, and the
 * generated encounters are tagged `created_by='demo-tuning'`. Runs last in DatabaseSeeder.
 * Assumes Postgres (uses gen_random_uuid / interval math). PostgreSQL-only demo.
 */
class DemoTuningSeeder extends Seeder
{
    // Per-unit-type occupancy target: ICUs run hotter than med/surg — a flat number across
    // every type reads as a global target, not a real house. Each value sits inside its
    // config('hospital.plausibility_targets.occupancy_by_unit_type') band; populateOccupancy()
    // only fills UP, so med/surg settles near ~85% while ICU and step-down are pulled higher.
    private const OCCUPANCY_TARGET = ['icu' => 0.92, 'step_down' => 0.86, 'med_surg' => 0.84];

    /** Acuity mix by unit type: [tier => weight]. ICUs skew high, med/surg low. */
    private const ACUITY = [
        'icu' => [2 => 1, 3 => 4, 4 => 5],
        'step_down' => [2 => 3, 3 => 5, 4 => 2],
        'med_surg' => [1 => 3, 2 => 5, 3 => 2],
    ];

    public function run(): void
    {
        $this->clampTemporalLeaks();
        $this->fixEdBedInventory();
        $this->dischargeOrphanedEncounters();
        $this->reconcileStrayEncounters();
        $this->populateOccupancy();
        $this->refreshSlas();
        $this->staffingToday();
        $this->varyOrSurgeons();
    }

    /**
     * 1b. Discharge active encounters stranded by unit-roster evolution so every
     *     live unit's census stays physically possible:
     *       - actives on soft-deleted units (retired legacy/CAD taxonomies);
     *       - per live inpatient unit, the oldest actives beyond its live bed count.
     *     Rows are kept (status flip only — the demo owns these synthetic rows);
     *     idempotent (no-ops once every unit fits).
     */
    private function dischargeOrphanedEncounters(): void
    {
        DB::update("
            UPDATE prod.encounters e
            SET status = 'discharged',
                discharged_at = COALESCE(e.discharged_at, now() AT TIME ZONE 'UTC'),
                updated_at = now()
            FROM prod.units u
            WHERE u.unit_id = e.unit_id AND u.is_deleted = true
              AND e.status = 'active' AND e.is_deleted = false
        ");

        DB::update("
            UPDATE prod.encounters SET
                status = 'discharged',
                discharged_at = COALESCE(discharged_at, now() AT TIME ZONE 'UTC'),
                updated_at = now()
            WHERE encounter_id IN (
                SELECT e.encounter_id
                FROM prod.encounters e
                JOIN prod.units u ON u.unit_id = e.unit_id AND u.is_deleted = false
                JOIN LATERAL (
                    SELECT count(*) AS bed_count
                    FROM prod.beds b
                    WHERE b.unit_id = u.unit_id AND b.is_deleted = false
                ) beds ON true
                WHERE e.status = 'active' AND e.is_deleted = false AND u.type <> 'ed'
                  AND (
                    SELECT count(*) FROM prod.encounters e2
                    WHERE e2.unit_id = e.unit_id AND e2.status = 'active' AND e2.is_deleted = false
                      AND (e2.admitted_at, e2.encounter_id) >= (e.admitted_at, e.encounter_id)
                  ) > beds.bed_count
            )
        ");
    }

    /**
     * 0. Correct seed-owned temporal leaks in the NON-scenario domains (encounters / census /
     *    predictions — the scenario's own staffing+transport are owned by OperationalDemoDataService)
     *    so the current snapshot is coherent (enforced by zephyrus:demo-validate):
     *      - purge expired forecasts once today/tomorrow replacements exist;
     *      - drop future-dated census "actuals" (a snapshot cannot be in the future);
     *      - pull active encounters admitted in the future back to a plausible recent admit;
     *      - repair expected_discharge_date rows that precede admission.
     *    Idempotent (no-ops once clean).
     */
    private function clampTemporalLeaks(): void
    {
        DB::delete("DELETE FROM prod.rtdc_predictions WHERE service_date < (now() AT TIME ZONE 'UTC')::date");

        DB::delete("DELETE FROM prod.census_snapshots WHERE captured_at > (now() AT TIME ZONE 'UTC')");

        DB::update("
            UPDATE prod.encounters
            SET admitted_at = (now() AT TIME ZONE 'UTC') - (floor(random()*72)||' hours')::interval,
                updated_at = now()
            WHERE admitted_at > (now() AT TIME ZONE 'UTC') AND discharged_at IS NULL AND is_deleted = false
        ");

        DB::update("
            UPDATE prod.encounters
            SET expected_discharge_date = (admitted_at + ((2 + floor(random()*4))||' days')::interval)::date,
                updated_at = now()
            WHERE is_deleted = false AND expected_discharge_date IS NOT NULL AND expected_discharge_date < admitted_at::date
        ");
    }

    /** 1. Prune phantom "available" ED beds so the ED bed rows match its staffed count. */
    private function fixEdBedInventory(): void
    {
        DB::update("
            UPDATE prod.beds SET is_deleted = true
            WHERE unit_id IN (SELECT unit_id FROM prod.units WHERE type = 'ed')
              AND status = 'available' AND is_deleted = false
        ");
    }

    /** 2. Mark one available bed occupied per unit that has more active encounters than beds. */
    private function reconcileStrayEncounters(): void
    {
        $deficits = DB::select("
            SELECT u.unit_id,
              (SELECT count(*) FROM prod.encounters e WHERE e.unit_id = u.unit_id AND e.status = 'active' AND e.is_deleted = false)
              - (SELECT count(*) FROM prod.beds b WHERE b.unit_id = u.unit_id AND b.status = 'occupied' AND b.is_deleted = false) AS deficit
            FROM prod.units u WHERE u.is_deleted = false
        ");

        foreach ($deficits as $d) {
            if ((int) $d->deficit > 0) {
                DB::update('
                    UPDATE prod.beds SET status = \'occupied\' WHERE bed_id IN (
                        SELECT bed_id FROM prod.beds
                        WHERE unit_id = ? AND status = \'available\' AND is_deleted = false
                        ORDER BY bed_id LIMIT ?
                    )
                ', [$d->unit_id, (int) $d->deficit]);
            }
        }
    }

    /** 3. Bring each inpatient unit to ~85% occupancy with matching, bed-linked active encounters. */
    private function populateOccupancy(): void
    {
        $units = DB::select("
            SELECT u.unit_id, u.type, u.staffed_bed_count,
              (SELECT count(*) FROM prod.beds b WHERE b.unit_id = u.unit_id AND b.status = 'occupied' AND b.is_deleted = false) AS occ
            FROM prod.units u
            WHERE u.is_deleted = false AND u.type IN ('icu', 'step_down', 'med_surg')
            ORDER BY u.unit_id
        ");

        $sampler = new \App\Services\Demo\DistributionSampler;
        $seq = 0;
        foreach ($units as $u) {
            // Per-type target + a small deterministic per-unit jitter so units within a type vary.
            $base = self::OCCUPANCY_TARGET[$u->type] ?? 0.82;
            $target = $base + $sampler->valueInBand([-0.02, 0.02], (int) $u->unit_id);
            $goal = (int) round($target * (int) $u->staffed_bed_count);
            $delta = $goal - (int) $u->occ;
            if ($delta <= 0) {
                continue;
            }

            $beds = DB::select('
                SELECT bed_id FROM prod.beds
                WHERE unit_id = ? AND status = \'available\' AND is_deleted = false
                ORDER BY bed_id LIMIT ?
            ', [$u->unit_id, $delta]);

            foreach ($beds as $b) {
                DB::update('UPDATE prod.beds SET status = \'occupied\' WHERE bed_id = ?', [$b->bed_id]);
                $seq++;
                DB::insert("
                    INSERT INTO prod.encounters
                      (patient_ref, unit_id, bed_id, admitted_at, expected_discharge_date, acuity_tier,
                       status, created_at, updated_at, created_by, modified_by, is_deleted)
                    VALUES
                      (?, ?, ?,
                       (now() AT TIME ZONE 'UTC') - (floor(random()*6)||' days')::interval - (floor(random()*24)||' hours')::interval,
                       ((now() AT TIME ZONE 'UTC') + (floor(random()*5)||' days')::interval)::date,
                       ?, 'active', now(), now(), 'demo-tuning', 'demo-tuning', false)
                ", [sprintf('sim-occ-%04d', $seq), $u->unit_id, $b->bed_id, $this->weightedTier(self::ACUITY[$u->type])]);
            }
        }
    }

    /** 4. Refresh active EVS SLAs to a near-now spread (UTC-aligned wall clock). */
    private function refreshSlas(): void
    {
        // OperationalDemoDataService exclusively owns its transport scenario,
        // including the deterministic four-of-twenty overdue cohort. Rewriting
        // those deadlines here made strict demo validation nondeterministic.
        DB::update("
            UPDATE prod.evs_requests
            SET needed_at = (now() AT TIME ZONE 'UTC') + ((floor(random()*110) - 30)||' minutes')::interval
            WHERE status NOT IN ('completed', 'canceled', 'failed')
        ");
    }

    /** 5. Keep only the scenario-owned staffing request targets near the current rehearsal window. */
    private function staffingToday(): void
    {
        DB::update("
            UPDATE prod.staffing_requests
            SET needed_by = (now() AT TIME ZONE 'UTC') + ((floor(random()*180) - 30)||' minutes')::interval
            WHERE requested_by = ? AND status IN ('requested', 'open', 'sourcing', 'escalated')
        ", [OperationalDemoDataService::OWNER]);
    }

    /** 6. Round-robin the four flagship attendings across the anchor day so the board varies. */
    private function varyOrSurgeons(): void
    {
        $anchor = DB::scalar('SELECT max(surgery_date) FROM prod.or_cases WHERE is_deleted = false');
        if ($anchor === null) {
            return;
        }

        $surgeons = DB::select("
            SELECT provider_id FROM prod.providers
            WHERE name IN ('Dr. Sofia Marchetti', 'Dr. James Okonkwo', 'Dr. Hannah Wexler', 'Dr. Rahul Desai')
            ORDER BY provider_id
        ");
        if (count($surgeons) < 2) {
            return;
        }

        $cases = DB::select('
            SELECT case_id FROM prod.or_cases
            WHERE surgery_date = ? AND is_deleted = false
            ORDER BY room_id, scheduled_start_time
        ', [$anchor]);

        foreach ($cases as $i => $c) {
            $sid = $surgeons[$i % count($surgeons)]->provider_id;
            DB::update('UPDATE prod.or_cases SET primary_surgeon_id = ? WHERE case_id = ?', [$sid, $c->case_id]);
        }
    }

    /** Weighted random acuity tier. $weights = [tier => weight]. */
    private function weightedTier(array $weights): int
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);
        $acc = 0;
        foreach ($weights as $tier => $weight) {
            $acc += $weight;
            if ($roll <= $acc) {
                return $tier;
            }
        }

        return (int) array_key_first($weights);
    }
}
