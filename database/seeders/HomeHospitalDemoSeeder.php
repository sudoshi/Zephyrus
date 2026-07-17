<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeProgram;
use App\Models\Home\HomeReferral;
use App\Models\Home\HomeVisit;
use App\Models\Home\RpmDevice;
use App\Models\Home\RpmEnrollment;
use App\Models\Home\RpmKit;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

/**
 * Home Hospital Phase 0 demo cohort (ACUM-PRD-HAH-001; build brief §6.16).
 *
 * Seeds the virtual ward as one more prod.units row (type = virtual_home,
 * exempt from RtdcSeeder's manifest soft-trim) with prod.beds rows as program
 * slots, so census/occupancy/huddles/cockpit machinery work unmodified. Slot
 * states map onto the existing prod.beds CHECK (available|occupied|blocked|
 * dirty); "dirty" carries the pending-kit-setup meaning on this unit.
 *
 * Gated on HOME_HOSPITAL_ENABLED — a bare `db:seed` on a deployment without
 * the module leaves no trace. Idempotent: every row keys on a natural key
 * (program code, kit code, patient_ref) via updateOrCreate/firstOrCreate.
 * Ownership: encounters carry created_by = OWNER; home rows carry
 * metadata.provenance = 'demo' (drives the frontend ProvenanceBadge).
 */
class HomeHospitalDemoSeeder extends Seeder
{
    public const OWNER = 'home-hospital-demo';

    /**
     * Demo cohort: deterministic patient_refs; slot index = position on the
     * board. Conditions come from the §13 Q1 default set. day = day-of-stay.
     */
    private const EPISODES = [
        ['ref' => 'HOME-DEMO-001', 'condition' => 'heart_failure', 'label' => 'Heart Failure', 'day' => 4, 'los' => 6.0, 'tier' => 2, 'zone' => 'north'],
        ['ref' => 'HOME-DEMO-002', 'condition' => 'copd', 'label' => 'COPD Exacerbation', 'day' => 2, 'los' => 5.0, 'tier' => 3, 'zone' => 'north'],
        ['ref' => 'HOME-DEMO-003', 'condition' => 'pneumonia', 'label' => 'Pneumonia / Respiratory Infection', 'day' => 5, 'los' => 6.0, 'tier' => 3, 'zone' => 'central'],
        ['ref' => 'HOME-DEMO-004', 'condition' => 'cellulitis', 'label' => 'Cellulitis / SSTI', 'day' => 1, 'los' => 4.0, 'tier' => 4, 'zone' => 'central'],
        ['ref' => 'HOME-DEMO-005', 'condition' => 'uti', 'label' => 'Kidney / UTI', 'day' => 3, 'los' => 5.0, 'tier' => 3, 'zone' => 'south'],
        ['ref' => 'HOME-DEMO-006', 'condition' => 'heart_failure', 'label' => 'Heart Failure', 'day' => 6, 'los' => 6.0, 'tier' => 2, 'zone' => 'south'],
        ['ref' => 'HOME-DEMO-007', 'condition' => 'copd', 'label' => 'COPD Exacerbation', 'day' => 2, 'los' => 6.0, 'tier' => 3, 'zone' => 'north'],
        ['ref' => 'HOME-DEMO-008', 'condition' => 'pneumonia', 'label' => 'Pneumonia / Respiratory Infection', 'day' => 1, 'los' => 5.0, 'tier' => 3, 'zone' => 'central'],
    ];

    /** Referral funnel snapshot — includes declines with coded reasons (§11 selection-bias analytics). */
    private const REFERRALS = [
        ['ref' => 'HOME-REF-101', 'source' => 'ed_diversion', 'status' => 'screened', 'zone' => 'north', 'payer' => 'medicare_ffs'],
        ['ref' => 'HOME-REF-102', 'source' => 'ed_diversion', 'status' => 'eligible', 'zone' => 'central', 'payer' => 'medicare_advantage'],
        ['ref' => 'HOME-REF-103', 'source' => 'inpatient_stepdown', 'status' => 'consented', 'zone' => 'south', 'payer' => 'medicare_ffs'],
        ['ref' => 'HOME-REF-104', 'source' => 'inpatient_stepdown', 'status' => 'referred', 'zone' => 'north', 'payer' => 'commercial'],
        ['ref' => 'HOME-REF-105', 'source' => 'ed_diversion', 'status' => 'declined', 'zone' => 'central', 'payer' => 'medicaid', 'decline' => 'patient_preference'],
        ['ref' => 'HOME-REF-106', 'source' => 'ed_diversion', 'status' => 'declined', 'zone' => 'out_of_zone', 'payer' => 'medicare_ffs', 'decline' => 'out_of_service_zone'],
    ];

    public function run(): void
    {
        if (! (bool) config('home_hospital.enabled')) {
            return;
        }

        $unit = $this->seedVirtualUnit();
        $programs = $this->seedPrograms();
        $this->seedKits();
        $this->seedEpisodes($unit, $programs['ahcah_acute']);
        $this->seedReferrals($programs['ahcah_acute']);
    }

    private function seedVirtualUnit(): Unit
    {
        $slotCount = (int) config('home_hospital.slot_count', 12);

        $unit = Unit::updateOrCreate(
            ['abbreviation' => (string) config('home_hospital.unit_abbreviation', 'HOME')],
            [
                'name' => (string) config('home_hospital.unit_name', 'Summit Home Hospital — Virtual Ward'),
                'type' => 'virtual_home',
                'staffed_bed_count' => $slotCount,
                'ratio_floor' => 4,
                'created_by' => self::OWNER,
                'is_deleted' => false,
            ]
        );

        for ($i = 1; $i <= $slotCount; $i++) {
            Bed::firstOrCreate(
                ['unit_id' => $unit->unit_id, 'label' => sprintf('%s-%02d', $unit->abbreviation, $i)],
                ['status' => 'available', 'bed_type' => 'home_slot', 'created_by' => self::OWNER]
            );
        }

        return $unit;
    }

    /** @return array<string, HomeProgram> */
    private function seedPrograms(): array
    {
        $conditions = array_keys((array) config('home_hospital.conditions', []));

        $acute = HomeProgram::updateOrCreate(
            ['code' => 'ahcah_acute'],
            [
                'program_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.program.ahcah_acute')->toString(),
                'name' => 'Acute Hospital at Home (AHCAH waiver)',
                'program_type' => 'ahcah_acute',
                'conditions' => $conditions,
                'slot_capacity' => (int) config('home_hospital.slot_count', 12),
                'zone_slot_capacity' => ['north' => 4, 'central' => 4, 'south' => 4],
                'payer_rules' => ['medicare_ffs' => 'covered_waiver', 'medicare_advantage' => 'plan_dependent', 'commercial' => 'contract_dependent', 'medicaid' => 'state_dependent'],
                'is_active' => true,
                'metadata' => ['provenance' => 'demo'],
            ]
        );

        $postDischarge = HomeProgram::updateOrCreate(
            ['code' => 'post_discharge_rpm'],
            [
                'program_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.post_discharge_rpm')->toString(),
                'name' => '30-Day Post-Discharge Monitoring',
                'program_type' => 'post_discharge_rpm',
                'conditions' => $conditions,
                'slot_capacity' => 0,
                'is_active' => true,
                'metadata' => ['provenance' => 'demo'],
            ]
        );

        return ['ahcah_acute' => $acute, 'post_discharge_rpm' => $postDischarge];
    }

    private function seedKits(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $kit = RpmKit::updateOrCreate(
                ['kit_code' => sprintf('KIT-%03d', $i)],
                [
                    'kit_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.kit.'.$i)->toString(),
                    'vendor' => 'Synthetic Health',
                    'model' => 'SH-100',
                    'status' => 'available',
                    'connectivity' => 'cellular',
                    'battery_pct' => 100,
                    'metadata' => ['provenance' => 'demo'],
                ]
            );

            foreach (['bp_monitor', 'pulse_oximeter', 'thermometer', 'scale'] as $type) {
                RpmDevice::updateOrCreate(
                    ['rpm_kit_id' => $kit->rpm_kit_id, 'device_type' => $type],
                    [
                        'device_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.device.'.$i.'.'.$type)->toString(),
                        'serial_number' => sprintf('SH100-%03d-%s', $i, strtoupper(substr($type, 0, 2))),
                        'status' => 'active',
                        'battery_pct' => 90,
                        'metadata' => ['provenance' => 'demo'],
                    ]
                );
            }
        }
    }

    private function seedEpisodes(Unit $unit, HomeProgram $program): void
    {
        $slots = Bed::where('unit_id', $unit->unit_id)->where('is_deleted', false)->orderBy('bed_id')->get()->values();
        $kits = RpmKit::where('is_deleted', false)->orderBy('rpm_kit_id')->get()->values();

        foreach (self::EPISODES as $i => $spec) {
            $bed = $slots[$i] ?? null;
            if ($bed === null) {
                continue;
            }

            $admittedAt = now()->subDays($spec['day'])->subHours(3);
            $expected = now()->addDays(max(0, (int) ceil($spec['los'] - $spec['day'])))->toDateString();

            $encounter = Encounter::updateOrCreate(
                ['patient_ref' => $spec['ref'], 'unit_id' => $unit->unit_id, 'status' => 'active'],
                [
                    'bed_id' => $bed->bed_id,
                    'admitted_at' => $admittedAt,
                    'expected_discharge_date' => $expected,
                    'acuity_tier' => $spec['tier'],
                    'created_by' => self::OWNER,
                    'is_deleted' => false,
                ]
            );

            if ($bed->status !== 'occupied') {
                $bed->update(['status' => 'occupied', 'modified_by' => self::OWNER]);
            }

            $episode = HomeEpisode::updateOrCreate(
                ['patient_ref' => $spec['ref'], 'home_program_id' => $program->home_program_id],
                [
                    'episode_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.episode.'.$spec['ref'])->toString(),
                    'encounter_id' => $encounter->encounter_id,
                    'condition_code' => $spec['condition'],
                    'condition_label' => $spec['label'],
                    'admission_source' => $i % 2 === 0 ? 'ed_diversion' : 'inpatient_stepdown',
                    'acuity_tier' => $spec['tier'],
                    'status' => 'active',
                    'service_zone' => $spec['zone'],
                    'target_los_days' => $spec['los'],
                    'expected_discharge_date' => $expected,
                    'started_at' => $admittedAt,
                    'metadata' => ['provenance' => 'demo'],
                ]
            );

            $kit = $kits[$i] ?? null;
            if ($kit !== null) {
                RpmEnrollment::updateOrCreate(
                    ['home_episode_id' => $episode->home_episode_id, 'rpm_kit_id' => $kit->rpm_kit_id],
                    [
                        'enrollment_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.enrollment.'.$spec['ref'])->toString(),
                        'patient_ref' => $spec['ref'],
                        'status' => 'active',
                        'monitoring_plan' => ['cadence_minutes' => ['8867-4' => 60, '59408-5' => 60, '8480-6' => 240, '8462-4' => 240]],
                        'started_at' => $admittedAt,
                        'metadata' => ['provenance' => 'demo'],
                    ]
                );
                if ($kit->status !== 'assigned') {
                    $kit->update(['status' => 'assigned']);
                }
            }

            $this->seedVisits($episode, $spec['ref']);
        }

        // Two slots beyond the cohort model non-available states: one blocked
        // (home-safety hold), one dirty (= pending kit setup on this unit).
        $blocked = $slots[count(self::EPISODES)] ?? null;
        if ($blocked !== null && $blocked->status === 'available') {
            $blocked->update(['status' => 'blocked', 'modified_by' => self::OWNER]);
        }
        $pending = $slots[count(self::EPISODES) + 1] ?? null;
        if ($pending !== null && $pending->status === 'available') {
            $pending->update(['status' => 'dirty', 'modified_by' => self::OWNER]);
        }
    }

    private function seedVisits(HomeEpisode $episode, string $ref): void
    {
        // Waiver operating floor: two in-person visits today (§3). One completed
        // this morning, one upcoming — feeds the compliance rail and the
        // next-visit countdown without hand-tuned clock states.
        $visits = [
            ['type' => 'rn', 'start' => now()->startOfDay()->addHours(9), 'status' => 'completed', 'waiver' => true],
            ['type' => 'community_paramedic', 'start' => now()->startOfDay()->addHours(18), 'status' => 'scheduled', 'waiver' => true],
            ['type' => 'md_np_tele', 'start' => now()->startOfDay()->addHours(11), 'status' => 'completed', 'waiver' => false],
        ];

        foreach ($visits as $j => $v) {
            $completed = $v['status'] === 'completed';
            HomeVisit::updateOrCreate(
                [
                    'home_episode_id' => $episode->home_episode_id,
                    'visit_type' => $v['type'],
                    'scheduled_start' => $v['start'],
                ],
                [
                    'visit_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.visit.'.$ref.'.'.$j.'.'.$v['start']->toDateString()),
                    'patient_ref' => $ref,
                    'is_waiver_required' => $v['waiver'],
                    'status' => $v['status'],
                    'started_at' => $completed ? $v['start'] : null,
                    'completed_at' => $completed ? $v['start']->copy()->addMinutes(40) : null,
                    'on_time' => $completed ? true : null,
                    'metadata' => ['provenance' => 'demo'],
                ]
            );
        }
    }

    private function seedReferrals(HomeProgram $program): void
    {
        foreach (self::REFERRALS as $spec) {
            $declined = $spec['status'] === 'declined';
            HomeReferral::updateOrCreate(
                ['patient_ref' => $spec['ref'], 'home_program_id' => $program->home_program_id],
                [
                    'referral_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.home.referral.'.$spec['ref'])->toString(),
                    'source' => $spec['source'],
                    'status' => $spec['status'],
                    'decline_reason' => $spec['decline'] ?? null,
                    'declined_at' => $declined ? now()->subHours(5) : null,
                    'screening' => [
                        'service_zone' => $spec['zone'],
                        'payer_class' => $spec['payer'],
                        'connectivity' => 'cellular_ok',
                        'home_safety' => $declined ? 'not_assessed' : 'passed',
                    ],
                    'payer_class' => $spec['payer'],
                    'service_zone' => $spec['zone'],
                    'referred_by' => self::OWNER,
                    'referred_at' => now()->subHours(8),
                    'status_changed_at' => now()->subHours(2),
                    'metadata' => ['provenance' => 'demo'],
                ]
            );
        }
    }
}
