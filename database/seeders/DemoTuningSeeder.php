<?php

namespace Database\Seeders;

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
    private const TARGET_OCCUPANCY = 0.85;

    /** Acuity mix by unit type: [tier => weight]. ICUs skew high, med/surg low. */
    private const ACUITY = [
        'icu' => [2 => 1, 3 => 4, 4 => 5],
        'step_down' => [2 => 3, 3 => 5, 4 => 2],
        'med_surg' => [1 => 3, 2 => 5, 3 => 2],
    ];

    public function run(): void
    {
        $this->fixEdBedInventory();
        $this->reconcileStrayEncounters();
        $this->populateOccupancy();
        $this->refreshSlas();
        $this->staffingToday();
        $this->varyOrSurgeons();
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

        $seq = 0;
        foreach ($units as $u) {
            $goal = (int) round(self::TARGET_OCCUPANCY * (int) $u->staffed_bed_count);
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

    /** 4. Refresh active transport/EVS SLAs to a near-now spread (UTC-aligned wall clock). */
    private function refreshSlas(): void
    {
        DB::update("
            UPDATE prod.transport_requests
            SET needed_at = (now() AT TIME ZONE 'UTC') + ((floor(random()*150) - 40)||' minutes')::interval
            WHERE is_deleted = false AND status NOT IN ('completed', 'canceled', 'failed')
        ");
        DB::update("
            UPDATE prod.evs_requests
            SET needed_at = (now() AT TIME ZONE 'UTC') + ((floor(random()*110) - 30)||' minutes')::interval
            WHERE status NOT IN ('completed', 'canceled', 'failed')
        ");
    }

    /** 5. (Re)build today's staffing plans (UTC date) from the latest prior day, with real gaps. */
    private function staffingToday(): void
    {
        DB::delete("DELETE FROM prod.staffing_plans WHERE shift_date = (now() AT TIME ZONE 'UTC')::date AND notes = 'demo-today'");

        DB::insert("
            INSERT INTO prod.staffing_plans
              (plan_uuid, unit_id, unit_label, role, shift_date, shift, required_count, scheduled_count,
               actual_count, minimum_safe_count, census, ratio_target, status, notes, created_at, updated_at, is_deleted)
            SELECT gen_random_uuid(), unit_id, unit_label, role, (now() AT TIME ZONE 'UTC')::date, shift,
                   required_count, scheduled_count, actual_count, minimum_safe_count, census, ratio_target,
                   'balanced', 'demo-today', now(), now(), false
            FROM prod.staffing_plans
            WHERE shift_date = (
                SELECT max(shift_date) FROM prod.staffing_plans
                WHERE shift_date < (now() AT TIME ZONE 'UTC')::date AND (notes IS DISTINCT FROM 'demo-today')
            )
        ");

        DB::update("
            UPDATE prod.staffing_plans
            SET scheduled_count = greatest(required_count - 2, 0), actual_count = greatest(required_count - 2, 0),
                minimum_safe_count = greatest(required_count - 1, 1), status = 'critical_gap'
            WHERE shift_date = (now() AT TIME ZONE 'UTC')::date AND notes = 'demo-today' AND role = 'rn'
              AND unit_id IN (SELECT unit_id FROM prod.units WHERE abbreviation = 'MICU')
        ");
        DB::update("
            UPDATE prod.staffing_plans
            SET scheduled_count = greatest(required_count - 1, 0), actual_count = greatest(required_count - 1, 0),
                minimum_safe_count = greatest(required_count - 1, 1), status = 'gap'
            WHERE shift_date = (now() AT TIME ZONE 'UTC')::date AND notes = 'demo-today' AND role = 'rn'
              AND unit_id IN (SELECT unit_id FROM prod.units WHERE abbreviation IN ('6E', 'SICU', '7E'))
        ");
        DB::update("
            UPDATE prod.staffing_requests
            SET needed_by = (now() AT TIME ZONE 'UTC') + ((floor(random()*180) - 30)||' minutes')::interval
            WHERE status IN ('requested', 'open', 'sourcing', 'escalated')
        ");
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
