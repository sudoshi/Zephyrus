<?php

namespace App\Services\Demo;

use App\Models\Staffing\StaffingEvent;
use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class OperationalDemoDataService
{
    public const SCENARIO_ID = 'summit-500-current-operations-v1';

    public const OWNER = 'operations-demo:'.self::SCENARIO_ID;

    public const LEGACY_OWNER = 'demo-seeder';

    public const WORKFORCE_SOURCE = 'ZEPHYRUS_DEMO_ROSTER';

    private const HISTORY_DAYS = 60;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $roleTaxonomy = null;

    public function __construct(
        private readonly HospitalManifest $hospital,
        private readonly ServiceLineNormalizer $serviceLineNormalizer,
    ) {}

    /** @return array<string,int|string|array<int,array<string,mixed>>> */
    public function preview(?Carbon $anchor = null): array
    {
        $anchor ??= now();
        $units = Unit::query()->where('is_deleted', false)->orderBy('unit_id')->get();
        $blueprint = $this->staffingBlueprint($units, $anchor);
        $workforce = $this->workforceMembers($this->workforceBlueprint($units, $blueprint), $units, $anchor);

        return [
            'scenario_id' => self::SCENARIO_ID,
            'anchor_date' => $anchor->toDateString(),
            'unit_count' => $units->count(),
            'inpatient_beds' => (int) $units->whereIn('type', ['icu', 'med_surg', 'step_down'])->sum('staffed_bed_count'),
            'staffing_plan_count' => count($blueprint),
            'staffing_gap_count' => collect($blueprint)->where('gap', '>', 0)->count(),
            'workforce_member_count' => count($workforce),
            'workforce_role_count' => collect($workforce)->pluck('role_code')->unique()->count(),
            'active_transport_count' => 20,
            'historical_transport_count' => self::HISTORY_DAYS * 3,
            'collisions' => $this->staffingCollisions($blueprint, $anchor),
        ];
    }

    /** @return array<string,int|string> */
    public function rollForward(?Carbon $anchor = null): array
    {
        $anchor ??= now();
        $units = Unit::query()->where('is_deleted', false)->orderBy('unit_id')->get();
        if ($units->isEmpty()) {
            throw new RuntimeException('No active operational units exist; seed/import the facility before rolling demo data forward.');
        }

        $blueprint = $this->staffingBlueprint($units, $anchor);
        $collisions = $this->staffingCollisions($blueprint, $anchor);
        if ($collisions !== []) {
            throw new RuntimeException('Refusing to replace non-scenario staffing slots: '.implode(', ', array_column($collisions, 'slot')));
        }

        return DB::transaction(function () use ($anchor, $units, $blueprint): array {
            // The transport ledgers reject even FK-cascade deletion by default.
            // This transaction-local flag is the sole sanctioned reset path for
            // rows selected by the explicit synthetic ownership markers below.
            DB::statement("SELECT set_config('zephyrus.allow_transport_ledger_reset', 'on', true)");

            // Adopt only rows carrying the prior seeder's explicit ownership
            // marker. Unowned historical plans and operational rows are never
            // selected by date, status, or missing metadata.
            StaffingRequest::query()->where('requested_by', self::LEGACY_OWNER)->delete();
            TransportRequest::query()->where('requested_by', self::LEGACY_OWNER)->delete();

            StaffingRequest::query()->where('requested_by', self::OWNER)->delete();
            StaffingPlan::query()->where('notes', self::OWNER)->delete();
            TransportRequest::query()->where('requested_by', self::OWNER)->delete();

            $staffing = $this->seedStaffing($blueprint, $anchor);
            $workforce = $this->seedWorkforce($units, $blueprint, $anchor);
            $transport = $this->seedTransport($units, $anchor);

            return [
                'scenario_id' => self::SCENARIO_ID,
                'anchor_date' => $anchor->toDateString(),
                'staffing_plans' => $staffing['plans'],
                'staffing_requests' => $staffing['requests'],
                'workforce_members' => $workforce['members'],
                'workforce_active' => $workforce['active'],
                'workforce_inactive' => $workforce['inactive'],
                'workforce_assignments' => $workforce['assignments'],
                'workforce_roles' => $workforce['roles'],
                'workforce_units' => $workforce['units'],
                'transport_active' => $transport['active'],
                'transport_history' => $transport['history'],
                'transport_events' => $transport['events'],
            ];
        });
    }

    /**
     * @param  Collection<int,Unit>  $units
     * @return list<array<string,mixed>>
     */
    private function staffingBlueprint(Collection $units, Carbon $anchor): array
    {
        $rows = [];
        foreach ($units as $unit) {
            $manifestUnit = $this->hospital->unit((string) $unit->abbreviation) ?? [];
            foreach (['day', 'evening', 'night'] as $shift) {
                $requirements = $this->requirementsForUnit($unit, $manifestUnit, $shift);
                foreach ($requirements as $role => $requirement) {
                    $required = $requirement['required'];
                    if ($required < 1) {
                        continue;
                    }

                    $gap = $this->plannedGap((string) $unit->abbreviation, $shift, $role);
                    $available = max(0, $required - $gap);
                    $minimumSafe = max(1, (int) ceil($required * 0.85));
                    $rows[] = [
                        'unit' => $unit,
                        'role' => $role,
                        'shift' => $shift,
                        'required' => $required,
                        'scheduled' => $available,
                        'actual' => $available,
                        'minimum_safe' => $minimumSafe,
                        'census' => $requirement['census'],
                        'ratio_target' => $requirement['ratio'],
                        'gap' => $gap,
                        'status' => $gap === 0 ? 'balanced' : ($available < $minimumSafe ? 'critical_gap' : 'gap'),
                        'shift_date' => $anchor->toDateString(),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $manifestUnit
     * @return array<string,array{required:int,census:int,ratio:?float}>
     */
    private function requirementsForUnit(Unit $unit, array $manifestUnit, string $shift): array
    {
        $type = (string) $unit->type;
        $beds = max(1, (int) ($unit->staffed_bed_count ?? $manifestUnit['staffed_bed_count'] ?? 1));

        if ($type === 'ed') {
            $census = ['day' => 42, 'evening' => 48, 'night' => 34][$shift];

            return [
                'rn' => ['required' => (int) ceil($census / 4), 'census' => $census, 'ratio' => 4.0],
                'tech' => ['required' => (int) ceil($census / 8), 'census' => $census, 'ratio' => 8.0],
                'charge' => ['required' => $shift === 'night' ? 1 : 2, 'census' => $census, 'ratio' => null],
                'provider' => ['required' => (int) ceil($census / ($shift === 'night' ? 12 : 9)), 'census' => $census, 'ratio' => null],
                'respiratory' => ['required' => max(1, (int) ceil($census / 18)), 'census' => $census, 'ratio' => null],
                'unit_secretary' => ['required' => ['day' => 3, 'evening' => 2, 'night' => 1][$shift], 'census' => $census, 'ratio' => null],
            ];
        }

        if ($type === 'periop') {
            $census = ['day' => 36, 'evening' => 14, 'night' => 5][$shift];

            return [
                'rn' => ['required' => ['day' => 18, 'evening' => 8, 'night' => 3][$shift], 'census' => $census, 'ratio' => null],
                'tech' => ['required' => ['day' => 10, 'evening' => 4, 'night' => 2][$shift], 'census' => $census, 'ratio' => null],
                'charge' => ['required' => 1, 'census' => $census, 'ratio' => null],
                'provider' => ['required' => ['day' => 8, 'evening' => 3, 'night' => 1][$shift], 'census' => $census, 'ratio' => null],
                'respiratory' => ['required' => 1, 'census' => $census, 'ratio' => null],
                'unit_secretary' => ['required' => ['day' => 2, 'evening' => 1, 'night' => 1][$shift], 'census' => $census, 'ratio' => null],
            ];
        }

        $occupancy = 0.82 + ((crc32((string) $unit->abbreviation) % 8) / 100);
        $census = max(1, min($beds, (int) round($beds * $occupancy)));
        $ratio = (float) ($manifestUnit['nurse_ratio'] ?? ($type === 'icu' ? 2 : ($type === 'step_down' ? 4 : 5)));
        $isIcu = $type === 'icu';
        $isLpnSetting = in_array((string) $unit->abbreviation, ['BHU', 'AIR', '4W', '4E', '5W', '5E', '6W', '6E'], true);

        $requirements = [
            'rn' => ['required' => max(1, (int) ceil($census / $ratio)), 'census' => $census, 'ratio' => $ratio],
            'tech' => ['required' => max(1, (int) ceil($census / ($isIcu ? 6 : 10))), 'census' => $census, 'ratio' => $isIcu ? 6.0 : 10.0],
            'charge' => ['required' => 1, 'census' => $census, 'ratio' => null],
            'unit_secretary' => ['required' => $shift === 'night' ? 0 : 1, 'census' => $census, 'ratio' => null],
        ];

        if ($isIcu) {
            $requirements['respiratory'] = ['required' => max(1, (int) ceil($census / 8)), 'census' => $census, 'ratio' => 8.0];
            $requirements['provider'] = ['required' => 1, 'census' => $census, 'ratio' => null];
        }
        if ($isLpnSetting) {
            $requirements['lpn'] = ['required' => $shift === 'day' ? 2 : 1, 'census' => $census, 'ratio' => null];
        }

        return $requirements;
    }

    private function plannedGap(string $unit, string $shift, string $role): int
    {
        return [
            'MICU:night:rn' => 2,
            '6E:evening:rn' => 2,
            'ED:day:rn' => 2,
            'OR:day:tech' => 2,
            'BHU:night:tech' => 1,
        ]["{$unit}:{$shift}:{$role}"] ?? 0;
    }

    /**
     * @param  list<array<string,mixed>>  $blueprint
     * @return list<array{slot:string}>
     */
    private function staffingCollisions(array $blueprint, Carbon $anchor): array
    {
        $ownedIds = StaffingPlan::query()->where('notes', self::OWNER)->pluck('staffing_plan_id');
        $collisions = [];
        foreach ($blueprint as $row) {
            $exists = StaffingPlan::query()
                ->where('is_deleted', false)
                ->whereDate('shift_date', $anchor->toDateString())
                ->where('unit_id', $row['unit']->unit_id)
                ->where('role', $row['role'])
                ->where('shift', $row['shift'])
                ->when($ownedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('staffing_plan_id', $ownedIds))
                ->exists();
            if ($exists) {
                $collisions[] = ['slot' => "{$row['unit']->abbreviation}:{$row['shift']}:{$row['role']}"];
            }
        }

        return $collisions;
    }

    /**
     * @param  list<array<string,mixed>>  $blueprint
     * @return array{plans:int,requests:int}
     */
    private function seedStaffing(array $blueprint, Carbon $anchor): array
    {
        $requests = 0;
        foreach ($blueprint as $row) {
            /** @var Unit $unit */
            $unit = $row['unit'];
            $slot = "{$unit->abbreviation}:{$row['shift']}:{$row['role']}";
            $plan = StaffingPlan::create([
                'plan_uuid' => $this->uuid("staffing-plan:{$anchor->toDateString()}:{$slot}"),
                'unit_id' => $unit->unit_id,
                'unit_label' => $unit->name,
                'role' => $row['role'],
                'shift_date' => $row['shift_date'],
                'shift' => $row['shift'],
                'required_count' => $row['required'],
                'scheduled_count' => $row['scheduled'],
                'actual_count' => $row['actual'],
                'minimum_safe_count' => $row['minimum_safe'],
                'census' => $row['census'],
                'ratio_target' => $row['ratio_target'],
                'status' => $row['status'],
                'notes' => self::OWNER,
                'constraints' => [
                    'calculation' => 'census_and_care_setting',
                    'qualification_required' => true,
                    'relief_factor' => 1.18,
                    'not_a_regulatory_ratio' => true,
                ],
                'metadata' => $this->metadata(['slot' => $slot]),
                'is_deleted' => false,
            ]);

            if ($row['gap'] < 1) {
                continue;
            }

            $neededBy = $this->shiftStart($anchor, $row['shift'])->addMinutes(30);
            $request = StaffingRequest::create([
                'request_uuid' => $this->uuid("staffing-request:{$anchor->toDateString()}:{$slot}"),
                'unit_id' => $unit->unit_id,
                'staffing_plan_id' => $plan->staffing_plan_id,
                'unit_label' => $unit->name,
                'role' => $row['role'],
                'shift_date' => $row['shift_date'],
                'shift' => $row['shift'],
                'request_type' => 'fill_gap',
                'priority' => $row['status'] === 'critical_gap' ? 'stat' : 'urgent',
                'status' => 'open',
                'headcount_needed' => $row['gap'],
                'hours_needed' => 8,
                'requested_by' => self::OWNER,
                'needed_by' => $neededBy,
                'owner_name' => 'Central Staffing Office',
                'risk_flags' => $row['status'] === 'critical_gap' ? ['below_minimum_safe'] : ['coverage_gap'],
                'metadata' => $this->metadata(['slot' => $slot, 'mitigation_sequence' => ['float_pool', 'overtime', 'agency', 'on_call']]),
                'is_deleted' => false,
            ]);
            StaffingEvent::create([
                'event_uuid' => $this->uuid("staffing-event:requested:{$anchor->toDateString()}:{$slot}"),
                'staffing_request_id' => $request->staffing_request_id,
                'event_type' => 'staffing.requested',
                'from_status' => null,
                'to_status' => 'open',
                'payload' => ['headcount_needed' => $row['gap'], 'slot' => $slot],
                'source' => self::OWNER,
                'occurred_at' => $anchor->copy()->subMinutes(20 + $requests * 3),
                'created_at' => $anchor,
            ]);
            $requests++;
        }

        return ['plans' => count($blueprint), 'requests' => $requests];
    }

    /**
     * Build one coverage-derived roster group per unit/role plus shared hospital
     * services. The relief factor is a planning assumption, never a care ratio.
     *
     * @param  Collection<int,Unit>  $units
     * @param  list<array<string,mixed>>  $staffingBlueprint
     * @return list<array<string,mixed>>
     */
    private function workforceBlueprint(Collection $units, array $staffingBlueprint): array
    {
        $groups = [];
        foreach ($staffingBlueprint as $row) {
            /** @var Unit $unit */
            $unit = $row['unit'];
            $manifestUnit = $this->hospital->unit((string) $unit->abbreviation) ?? [];
            $roleCode = $this->canonicalWorkforceRole($unit, (string) $row['role']);
            $key = "unit:{$unit->unit_id}:{$roleCode}";
            $groups[$key] ??= [
                'key' => $key,
                'unit' => $unit,
                'unit_id' => (int) $unit->unit_id,
                'unit_label' => (string) $unit->name,
                'unit_abbreviation' => (string) $unit->abbreviation,
                'service_line_code' => $this->serviceLineNormalizer->canonical((string) ($manifestUnit['service_line'] ?? 'hospital_medicine')),
                'role_code' => $roleCode,
                'coverage_model' => 'in_house',
                'coverage_by_shift' => ['day' => 0, 'evening' => 0, 'night' => 0],
            ];
            $groups[$key]['coverage_by_shift'][$row['shift']] += (int) $row['required'];
        }

        foreach ($this->hospitalWideRequirements() as $requirement) {
            $key = 'hospital:'.$requirement['role_code'];
            $groups[$key] = [
                'key' => $key,
                'unit' => null,
                'unit_id' => null,
                'unit_label' => 'Hospital-wide',
                'unit_abbreviation' => 'HOSP',
                'service_line_code' => $requirement['service_line_code'],
                'role_code' => $requirement['role_code'],
                'coverage_model' => $requirement['coverage_model'] ?? 'in_house',
                'coverage_by_shift' => $requirement['coverage_by_shift'],
            ];
        }

        ksort($groups);

        $annualDays = (int) config('demo_data.workforce.annual_coverage_days', 365);
        $shiftHours = (float) config('demo_data.workforce.shift_hours', 8);
        $productiveHours = (float) config('demo_data.workforce.productive_hours_per_fte', 1664);
        $reliefFactor = (float) config('demo_data.workforce.relief_factor', 1.18);

        return collect($groups)->map(function (array $group) use ($annualDays, $shiftHours, $productiveHours, $reliefFactor): array {
            $annualCoverageHours = (int) round(array_sum($group['coverage_by_shift']) * $shiftHours * $annualDays);
            $baseFte = $productiveHours > 0 ? $annualCoverageHours / $productiveHours : 0;

            return $group + [
                'annual_coverage_hours' => $annualCoverageHours,
                'base_fte' => round($baseFte, 2),
                'roster_fte' => round($baseFte * $reliefFactor, 2),
                'productive_hours_per_fte' => $productiveHours,
                'relief_factor' => $reliefFactor,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @param  Collection<int,Unit>  $units
     * @return list<array<string,mixed>>
     */
    private function workforceMembers(array $groups, Collection $units, Carbon $anchor): array
    {
        $members = [];
        $ordinal = 0;

        foreach ($groups as $group) {
            $shiftCycle = [];
            foreach (['day', 'evening', 'night'] as $shift) {
                $shiftCycle = array_merge($shiftCycle, array_fill(0, (int) $group['coverage_by_shift'][$shift], $shift));
            }
            $shiftCycle = $shiftCycle === [] ? ['day'] : $shiftCycle;

            $groupMembers = [];
            $assignedFte = 0.0;
            $localOrdinal = 0;
            while ($assignedFte + 0.001 < (float) $group['roster_fte']) {
                $localOrdinal++;
                $ordinal++;
                $profile = $this->employmentProfile($group, $localOrdinal);
                $assignedFte += $profile['fte'];
                $preferredShift = $shiftCycle[($localOrdinal - 1) % count($shiftCycle)];
                $availability = $ordinal % 137 === 0 ? 'leave' : ($ordinal % 173 === 0 ? 'unavailable' : 'available');
                $credentialStatus = $ordinal % 251 === 0 ? 'expired' : ($ordinal % 113 === 0 ? 'expiring' : 'valid');
                $floatEligibility = $this->floatEligibility($group, $units);
                $externalId = sprintf('%s-%04d', strtoupper(str_replace([':', '_'], '-', $group['key'])), $localOrdinal);

                $groupMembers[] = [
                    'staff_key' => 'demo:'.self::SCENARIO_ID.':'.strtolower($externalId),
                    'external_id' => $externalId,
                    'display_name' => $this->workforceName($ordinal),
                    'email' => sprintf('synthetic.staff.%04d@summit.example.invalid', $ordinal),
                    'employee_type' => $profile['employee_type'],
                    'employment_class' => $profile['employment_class'],
                    'fte' => $profile['fte'],
                    'role_code' => $group['role_code'],
                    'service_line_code' => $group['service_line_code'],
                    'unit_id' => $group['unit_id'],
                    'unit_label' => $group['unit_label'],
                    'coverage_model' => $group['coverage_model'],
                    'preferred_shift' => $preferredShift,
                    'availability' => $availability,
                    'credential_status' => $credentialStatus,
                    'credentials' => $this->credentialsForRole((string) $group['role_code']),
                    'competencies' => $this->competenciesForRole((string) $group['role_code']),
                    'eligible_float_unit_ids' => $floatEligibility['ids'],
                    'eligible_float_units' => $floatEligibility['labels'],
                    'is_active' => true,
                ];
            }

            foreach ($groupMembers as $member) {
                $member['roster_calculation'] = [
                    'coverage_by_shift' => $group['coverage_by_shift'],
                    'annual_coverage_hours' => $group['annual_coverage_hours'],
                    'productive_hours_per_fte' => $group['productive_hours_per_fte'],
                    'base_fte' => $group['base_fte'],
                    'relief_factor' => $group['relief_factor'],
                    'roster_fte' => $group['roster_fte'],
                    'assigned_roster_fte' => round($assignedFte, 2),
                    'not_a_regulatory_ratio' => true,
                ];
                $members[] = $member;
            }
        }

        $inactiveCount = (int) config('demo_data.workforce.inactive_records', 12);
        $activeMembers = $members;
        for ($i = 0; $i < $inactiveCount && $activeMembers !== []; $i++) {
            $ordinal++;
            $template = $activeMembers[($i * 173) % count($activeMembers)];
            $externalId = sprintf('INACTIVE-%03d', $i + 1);
            $members[] = array_merge($template, [
                'staff_key' => 'demo:'.self::SCENARIO_ID.':'.strtolower($externalId),
                'external_id' => $externalId,
                'display_name' => $this->workforceName($ordinal),
                'email' => sprintf('synthetic.inactive.%03d@summit.example.invalid', $i + 1),
                'employment_class' => 'inactive',
                'fte' => 0.0,
                'availability' => 'inactive',
                'is_active' => false,
            ]);
        }

        return $members;
    }

    /**
     * Persist the roster only when the Phase 7 alignment tables exist. Stable
     * staff keys are upserted; only assignments belonging to those synthetic
     * identities are rebuilt.
     *
     * @param  Collection<int,Unit>  $units
     * @param  list<array<string,mixed>>  $staffingBlueprint
     * @return array{members:int,active:int,inactive:int,assignments:int,roles:int,units:int}
     */
    private function seedWorkforce(Collection $units, array $staffingBlueprint, Carbon $anchor): array
    {
        foreach (['hosp_ref.service_lines', 'hosp_ref.staff_roles', 'hosp_org.staff_members', 'hosp_org.staff_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Canonical workforce table {$table} is missing; apply the reviewed staffing-alignment migration chain first.");
            }
        }

        $groups = $this->workforceBlueprint($units, $staffingBlueprint);
        $this->assertWorkforceReferences($groups);
        $members = $this->workforceMembers($groups, $units, $anchor);
        $keys = array_column($members, 'staff_key');
        $now = $anchor->copy();

        $memberRows = array_map(function (array $member) use ($now): array {
            $metadata = $this->metadata([
                'owner' => self::OWNER,
                'role_code' => $member['role_code'],
                'home_unit' => $member['unit_label'],
                'employment_class' => $member['employment_class'],
                'preferred_shift' => $member['preferred_shift'],
                'availability' => $member['availability'],
                'credential_status' => $member['credential_status'],
                'credentials' => $member['credentials'],
                'competencies' => $member['competencies'],
                'eligible_float_unit_ids' => $member['eligible_float_unit_ids'],
                'eligible_float_units' => $member['eligible_float_units'],
                'roster_window' => [
                    'start' => $now->toDateString(),
                    'end' => $now->copy()->addDays((int) config('demo_data.workforce.roster_window_days', 28) - 1)->toDateString(),
                ],
                'roster_calculation' => $member['roster_calculation'],
            ]);

            return [
                'staff_key' => $member['staff_key'],
                'source_system' => self::WORKFORCE_SOURCE,
                'external_id' => $member['external_id'],
                'user_id' => null,
                'npi' => null,
                'license_no' => $member['credentials'] === [] ? null : 'DEMO-'.strtoupper(substr(sha1($member['staff_key']), 0, 12)),
                'display_name' => $member['display_name'],
                'email' => $member['email'],
                'employee_type' => $member['employee_type'],
                'employment_status' => $member['is_active'] ? ($member['availability'] === 'leave' ? 'leave' : 'active') : 'terminated',
                'is_active' => $member['is_active'],
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $members);

        foreach (array_chunk($memberRows, 400) as $chunk) {
            DB::table('hosp_org.staff_members')->upsert($chunk, ['staff_key'], [
                'source_system', 'external_id', 'user_id', 'npi', 'license_no', 'display_name', 'email',
                'employee_type', 'employment_status', 'is_active', 'last_seen_at', 'metadata', 'updated_at',
            ]);
        }

        DB::table('hosp_org.staff_members')
            ->where('source_system', self::WORKFORCE_SOURCE)
            ->whereNotIn('staff_key', $keys)
            ->delete();

        $memberIds = DB::table('hosp_org.staff_members')
            ->where('source_system', self::WORKFORCE_SOURCE)
            ->whereIn('staff_key', $keys)
            ->pluck('staff_member_id', 'staff_key');
        DB::table('hosp_org.staff_assignments')->whereIn('staff_member_id', $memberIds->values())->delete();

        $assignmentRows = [];
        foreach ($members as $member) {
            $staffMemberId = $memberIds[$member['staff_key']] ?? null;
            if ($staffMemberId === null) {
                throw new RuntimeException('Synthetic workforce identity upsert did not return a stable staff member.');
            }

            $assignmentRows[] = [
                'staff_member_id' => $staffMemberId,
                'facility_key' => $this->hospital->facilityKey(),
                'service_line_code' => $member['service_line_code'],
                'role_code' => $member['role_code'],
                'program_code' => null,
                'unit_id' => $member['unit_id'],
                'primary_flag' => true,
                'coverage_model' => $member['coverage_model'],
                'fte' => $member['fte'],
                'confidence' => 1,
                'resolution_source' => 'synthetic_generator',
                'review_status' => 'source_verified',
                'evidence' => json_encode($this->metadata([
                    'owner' => self::OWNER,
                    'preferred_shift' => $member['preferred_shift'],
                    'availability' => $member['availability'],
                    'credential_status' => $member['credential_status'],
                    'employment_class' => $member['employment_class'],
                    'roster_calculation' => $member['roster_calculation'],
                ]), JSON_THROW_ON_ERROR),
                'effective_start' => $anchor->toDateString(),
                'effective_end' => $anchor->copy()->addDays((int) config('demo_data.workforce.roster_window_days', 28) - 1)->toDateString(),
                'is_active' => $member['is_active'],
                'decided_by' => null,
                'decided_at' => $anchor,
                'created_at' => $anchor,
                'updated_at' => $anchor,
            ];
        }
        foreach (array_chunk($assignmentRows, 400) as $chunk) {
            DB::table('hosp_org.staff_assignments')->insert($chunk);
        }

        $active = collect($members)->where('is_active', true);

        return [
            'members' => count($members),
            'active' => $active->count(),
            'inactive' => count($members) - $active->count(),
            'assignments' => count($assignmentRows),
            'roles' => collect($members)->pluck('role_code')->unique()->count(),
            'units' => $active->pluck('unit_id')->filter()->unique()->count(),
        ];
    }

    /** @param  list<array<string,mixed>>  $groups */
    private function assertWorkforceReferences(array $groups): void
    {
        $requiredRoles = collect($groups)->pluck('role_code')->unique()->values();
        $registeredRoles = DB::table('hosp_ref.staff_roles')
            ->whereIn('role_code', $requiredRoles)
            ->pluck('role_code');
        $missingRoles = $requiredRoles->diff($registeredRoles)->values();

        $requiredServiceLines = collect($groups)->pluck('service_line_code')->unique()->values();
        $registeredServiceLines = DB::table('hosp_ref.service_lines')
            ->whereIn('service_line_code', $requiredServiceLines)
            ->pluck('service_line_code');
        $missingServiceLines = $requiredServiceLines->diff($registeredServiceLines)->values();

        if ($missingRoles->isNotEmpty() || $missingServiceLines->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Canonical workforce references are incomplete (roles: %s; service lines: %s). Run deployment:seed-registry and deployment:seed-staff-roles before demo roll-forward.',
                $missingRoles->isEmpty() ? 'none' : $missingRoles->implode(', '),
                $missingServiceLines->isEmpty() ? 'none' : $missingServiceLines->implode(', '),
            ));
        }
    }

    /** @return list<array{role_code:string,service_line_code:string,coverage_by_shift:array{day:int,evening:int,night:int},coverage_model?:string}> */
    private function hospitalWideRequirements(): array
    {
        $row = static fn (string $role, string $serviceLine, int $day, int $evening, int $night, string $model = 'in_house'): array => [
            'role_code' => $role,
            'service_line_code' => $serviceLine,
            'coverage_by_shift' => ['day' => $day, 'evening' => $evening, 'night' => $night],
            'coverage_model' => $model,
        ];

        return [
            $row('house_supervisor', 'logistics_support', 2, 2, 2),
            $row('bed_manager', 'logistics_support', 3, 2, 2),
            $row('float_pool_nurse', 'hospital_medicine', 14, 12, 10, 'float'),
            $row('rapid_response_nurse', 'critical_care', 2, 2, 2),
            $row('vascular_access_nurse', 'hospital_medicine', 3, 2, 1),
            $row('nurse_educator', 'quality_research_education', 5, 1, 0, 'daytime'),
            $row('case_manager', 'home_post_acute', 18, 7, 2, 'daytime'),
            $row('social_worker', 'home_post_acute', 8, 4, 2),
            $row('care_coordinator', 'home_post_acute', 10, 4, 1),
            $row('physical_therapist', 'rehabilitation', 12, 5, 2, 'daytime'),
            $row('occupational_therapist', 'rehabilitation', 8, 3, 1, 'daytime'),
            $row('speech_language_pathologist', 'rehabilitation', 5, 2, 1, 'daytime'),
            $row('dietitian', 'hospital_medicine', 6, 3, 1, 'daytime'),
            $row('pharmacist', 'pharmacy_medication', 8, 5, 3),
            $row('pharmacy_technician', 'pharmacy_medication', 14, 9, 5),
            $row('medical_laboratory_scientist', 'laboratory_pathology', 12, 9, 7),
            $row('phlebotomist', 'laboratory_pathology', 10, 6, 4),
            $row('radiologic_technologist', 'imaging_diagnostics', 8, 5, 3),
            $row('ct_technologist', 'imaging_diagnostics', 5, 4, 2),
            $row('mri_technologist', 'imaging_diagnostics', 3, 2, 1),
            $row('sonographer', 'imaging_diagnostics', 4, 3, 1),
            $row('transport_tech', 'logistics_support', 14, 10, 7),
            $row('environmental_services', 'logistics_support', 36, 26, 20),
            $row('security', 'logistics_support', 10, 8, 7),
            $row('food_services', 'logistics_support', 24, 15, 6),
            $row('supply_chain_technician', 'logistics_support', 10, 6, 3),
            $row('biomedical_equipment_technician', 'logistics_support', 4, 2, 1),
            $row('facilities_technician', 'logistics_support', 8, 5, 3),
            $row('medical_interpreter', 'logistics_support', 5, 3, 2),
            $row('sterile_processing_technician', 'perioperative', 12, 9, 6),
            $row('patient_sitter', 'behavioral_health', 8, 8, 8),
            $row('telemetry_technician', 'cardiovascular', 5, 4, 4),
            $row('registration_specialist', 'emergency', 16, 10, 7),
            $row('chaplain', 'geriatrics_palliative', 3, 1, 1, 'on_call'),
            $row('infection_preventionist', 'infectious_disease_infection_prevention', 6, 2, 1, 'daytime'),
            $row('trauma_surgeon', 'trauma_acute_care_surgery', 2, 2, 1, 'on_call'),
            $row('neurosurgeon', 'neurosciences', 2, 1, 1, 'on_call'),
            $row('cardiologist', 'cardiovascular', 4, 2, 1, 'on_call'),
            $row('pediatrician', 'pediatrics', 4, 2, 2),
            $row('obstetrician_gynecologist', 'womens_health', 4, 2, 2, 'on_call'),
            $row('psychiatrist', 'behavioral_health', 3, 2, 1, 'on_call'),
            $row('radiologist', 'imaging_diagnostics', 5, 3, 2, 'tele'),
            $row('pathologist', 'laboratory_pathology', 3, 1, 1, 'on_call'),
            $row('nephrologist', 'renal_dialysis', 3, 1, 1, 'on_call'),
            $row('pulmonologist', 'pulmonary_respiratory', 3, 1, 1, 'on_call'),
            $row('palliative_care_physician', 'geriatrics_palliative', 2, 1, 1, 'on_call'),
            $row('nurse_practitioner', 'hospital_medicine', 8, 5, 3),
            $row('physician_assistant', 'hospital_medicine', 6, 4, 2),
            $row('certified_registered_nurse_anesthetist', 'perioperative', 10, 4, 2, 'on_call'),
            $row('perfusionist', 'cardiovascular', 3, 2, 1, 'on_call'),
        ];
    }

    private function canonicalWorkforceRole(Unit $unit, string $operationalRole): string
    {
        if ($operationalRole === 'rn') {
            return match ((string) $unit->abbreviation) {
                'ED' => 'emergency_nurse',
                'OR' => 'perioperative_nurse',
                'NICU' => 'neonatal_nurse',
                'PED', 'PICU' => 'pediatric_nurse',
                'BHU' => 'behavioral_health_nurse',
                default => $unit->type === 'icu' ? 'critical_care_nurse' : 'staff_nurse',
            };
        }

        if ($operationalRole === 'tech') {
            return match ((string) $unit->abbreviation) {
                'ED' => 'emergency_department_technician',
                'OR' => 'surgical_technologist',
                'BHU' => 'behavioral_health_technician',
                default => 'patient_care_technician',
            };
        }

        return match ($operationalRole) {
            'lpn' => 'licensed_practical_nurse',
            'charge' => 'charge_nurse',
            'provider' => match ((string) $unit->abbreviation) {
                'ED' => 'emergency_physician',
                'OR' => 'anesthesiologist',
                default => $unit->type === 'icu' ? 'intensivist' : 'hospitalist',
            },
            'respiratory' => 'respiratory_therapist',
            'unit_secretary' => 'unit_clerk',
            default => $operationalRole,
        };
    }

    /** @return array{employee_type:string,employment_class:string,fte:float} */
    private function employmentProfile(array $group, int $ordinal): array
    {
        if ($group['coverage_model'] === 'float') {
            return ['employee_type' => 'employed', 'employment_class' => 'float_pool', 'fte' => $ordinal % 5 === 0 ? 0.6 : 0.9];
        }
        if ($group['coverage_model'] === 'on_call') {
            return ['employee_type' => $ordinal % 4 === 0 ? 'locum' : 'contracted', 'employment_class' => 'on_call', 'fte' => $ordinal % 3 === 0 ? 0.3 : 0.5];
        }

        return [
            ['employee_type' => 'employed', 'employment_class' => 'full_time', 'fte' => 1.0],
            ['employee_type' => 'employed', 'employment_class' => 'full_time', 'fte' => 1.0],
            ['employee_type' => 'employed', 'employment_class' => 'full_time', 'fte' => 1.0],
            ['employee_type' => 'employed', 'employment_class' => 'full_time', 'fte' => 1.0],
            ['employee_type' => 'employed', 'employment_class' => 'full_time', 'fte' => 1.0],
            ['employee_type' => 'employed', 'employment_class' => 'part_time', 'fte' => 0.8],
            ['employee_type' => 'employed', 'employment_class' => 'part_time', 'fte' => 0.6],
            ['employee_type' => 'employed', 'employment_class' => 'per_diem', 'fte' => 0.3],
            ['employee_type' => 'agency', 'employment_class' => 'traveler', 'fte' => 0.9],
            ['employee_type' => 'contracted', 'employment_class' => 'on_call', 'fte' => 0.2],
        ][($ordinal - 1) % 10];
    }

    /** @return array{ids:list<int>,labels:list<string>} */
    private function floatEligibility(array $group, Collection $units): array
    {
        if ($group['unit_id'] === null) {
            return ['ids' => [], 'labels' => []];
        }

        $role = (string) $group['role_code'];
        $home = $group['unit'];
        $eligible = match (true) {
            in_array($role, ['critical_care_nurse', 'intensivist'], true) => $units->where('type', 'icu'),
            in_array($role, ['staff_nurse', 'licensed_practical_nurse', 'patient_care_technician', 'charge_nurse', 'unit_clerk'], true) => $units->whereIn('type', ['med_surg', 'step_down']),
            $role === 'pediatric_nurse' => $units->whereIn('abbreviation', ['PED', 'PICU']),
            default => $units->where('unit_id', $home->unit_id),
        };

        return [
            'ids' => $eligible->pluck('unit_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'labels' => $eligible->pluck('name')->map(fn ($name): string => (string) $name)->values()->all(),
        ];
    }

    /** @return list<string> */
    private function credentialsForRole(string $roleCode): array
    {
        $row = $this->staffRoleTaxonomy()[$roleCode] ?? [];
        if (($row['provider'] ?? false) === true) {
            return ['medical_staff_privileges', 'state_professional_license'];
        }
        if (($row['nursing'] ?? false) === true) {
            return [str_contains($roleCode, 'practical') ? 'LPN' : 'RN', 'BLS'];
        }

        return match ($roleCode) {
            'respiratory_therapist' => ['RRT', 'BLS'],
            'pharmacist' => ['RPh'],
            'physical_therapist' => ['PT'],
            'occupational_therapist' => ['OTR/L'],
            'speech_language_pathologist' => ['CCC-SLP'],
            'medical_laboratory_scientist' => ['MLS'],
            'radiologic_technologist', 'ct_technologist', 'mri_technologist' => ['ARRT'],
            'surgical_technologist' => ['CST'],
            default => [],
        };
    }

    /** @return list<string> */
    private function competenciesForRole(string $roleCode): array
    {
        return match ($roleCode) {
            'critical_care_nurse', 'intensivist', 'rapid_response_nurse' => ['critical_care', 'telemetry', 'code_response'],
            'emergency_nurse', 'emergency_physician', 'emergency_department_technician' => ['emergency_care', 'trauma_response'],
            'perioperative_nurse', 'anesthesiologist', 'surgical_technologist' => ['perioperative', 'sterile_field'],
            'neonatal_nurse' => ['neonatal_resuscitation', 'nicu'],
            'pediatric_nurse', 'pediatrician' => ['pediatric_care'],
            'behavioral_health_nurse', 'behavioral_health_technician', 'patient_sitter' => ['de_escalation', 'safe_observation'],
            'respiratory_therapist' => ['ventilator_management', 'airway_response'],
            'transport_tech' => ['patient_mobility', 'oxygen_transport'],
            default => ['hospital_orientation'],
        };
    }

    /** @return array<string,array<string,mixed>> */
    private function staffRoleTaxonomy(): array
    {
        if ($this->roleTaxonomy === null) {
            $config = require base_path('config/hospital/staff-roles.php');
            $this->roleTaxonomy = $config['staff_roles'] ?? [];
        }

        return $this->roleTaxonomy;
    }

    private function workforceName(int $ordinal): string
    {
        $firstNames = ['Avery', 'Jordan', 'Morgan', 'Riley', 'Cameron', 'Taylor', 'Parker', 'Reese', 'Casey', 'Drew', 'Quinn', 'Rowan', 'Skyler', 'Emerson', 'Hayden', 'Alexis', 'Blair', 'Dana', 'Elliot', 'Frankie', 'Harper', 'Jamie', 'Kendall', 'Logan', 'Marin', 'Noel', 'Payton', 'Robin', 'Sage', 'Terry', 'Val', 'Winter'];
        $lastNames = ['Adams', 'Bennett', 'Brooks', 'Campbell', 'Carter', 'Chen', 'Clark', 'Collins', 'Cooper', 'Davis', 'Diaz', 'Edwards', 'Evans', 'Flores', 'Foster', 'Garcia', 'Gray', 'Green', 'Hall', 'Harris', 'Hayes', 'Hill', 'Howard', 'Hughes', 'Jackson', 'James', 'Jenkins', 'Johnson', 'Kelly', 'Kim', 'King', 'Lee', 'Lewis', 'Long', 'Martin', 'Martinez', 'Miller', 'Mitchell', 'Moore', 'Morales', 'Morgan', 'Morris', 'Murphy', 'Nguyen', 'Ortiz', 'Patel', 'Perry', 'Price', 'Reed', 'Rivera', 'Roberts', 'Ross', 'Sanchez', 'Scott', 'Shah', 'Smith', 'Stewart', 'Thomas', 'Turner', 'Walker', 'Ward', 'Watson', 'White', 'Williams', 'Wilson', 'Wright', 'Young'];
        $index = $ordinal - 1;
        $first = $firstNames[$index % count($firstNames)];
        $last = $lastNames[intdiv($index, count($firstNames)) % count($lastNames)];
        $middle = chr(65 + (intdiv($index, count($firstNames) * count($lastNames)) % 26));

        return "{$first} {$middle}. {$last}";
    }

    /** @return array{active:int,history:int,events:int} */
    private function seedTransport(Collection $units, Carbon $anchor): array
    {
        $active = 0;
        $history = 0;
        $events = 0;

        for ($i = 0; $i < 20; $i++) {
            $scenario = $this->transportScenario($units, $i, true);
            $requestedAt = $anchor->copy()->subMinutes(12 + ($i * 5));
            $neededAt = $requestedAt->copy()->addMinutes([25, 40, 55, 70][$i % 4]);
            $status = $scenario['statuses'][$i % count($scenario['statuses'])];
            $events += $this->createTransport($scenario, $status, $requestedAt, $neededAt, "active:{$i}");
            $active++;
        }

        for ($day = 0; $day < self::HISTORY_DAYS; $day++) {
            for ($ordinal = 0; $ordinal < 3; $ordinal++) {
                $i = ($day * 3) + $ordinal;
                $scenario = $this->transportScenario($units, $i, false);
                $requestedAt = $anchor->copy()->subDays($day)->startOfDay()->addHours(7 + ($ordinal * 5))->addMinutes($i % 23);
                if ($requestedAt->isFuture()) {
                    $requestedAt = $anchor->copy()->subMinutes(90 + ($ordinal * 20));
                }
                $neededAt = $requestedAt->copy()->addMinutes(50);
                $status = $i % 13 === 0 ? 'canceled' : 'completed';
                $events += $this->createTransport($scenario, $status, $requestedAt, $neededAt, "history:{$day}:{$ordinal}");
                $history++;
            }
        }

        return ['active' => $active, 'history' => $history, 'events' => $events];
    }

    /** @return array<string,mixed> */
    private function transportScenario(Collection $units, int $index, bool $active): array
    {
        $type = ['inpatient', 'transfer', 'discharge', 'ems', 'care_transition'][$index % 5];
        $originUnit = $units->get($index % $units->count());
        $icu = $units->firstWhere('type', 'icu') ?? $originUnit;
        $ed = $units->firstWhere('type', 'ed') ?? $originUnit;
        $diagnostic = ['CT Scanner 2', 'MRI Suite 1', 'Interventional Radiology', 'Hemodialysis', 'Perioperative Holding'][$index % 5];
        $vendorNames = array_column($this->hospital->transport()['vendors'] ?? [], 'name');
        $nemt = $vendorNames[0] ?? 'Contracted NEMT';
        $ambulance = $vendorNames[1] ?? 'Contracted Ambulance';
        $internal = $this->hospital->transport()['internal_team']['name'] ?? 'Patient Transport';
        $postAcute = $this->hospital->postAcuteNames();

        $base = [
            'type' => $type,
            'priority' => ['routine', 'urgent', 'routine', 'stat'][$index % 4],
            'origin' => $originUnit->name,
            'destination' => $diagnostic,
            'mode' => ['wheelchair', 'stretcher', 'bed'][$index % 3],
            'service' => 'Hospital Medicine',
            'team' => $internal,
            'vendor' => null,
            'risk_flags' => $index % 4 === 0 ? ['oxygen', 'fall_risk'] : ['fall_risk'],
            'statuses' => ['requested', 'assigned', 'dispatched', 'arrived_pickup', 'patient_ready', 'picked_up', 'en_route', 'arrived_destination'],
        ];

        return match ($type) {
            'transfer' => array_merge($base, [
                'origin' => ['Riverton Community Hospital ED', 'Glenmoore Medical Center ED'][$index % 2],
                'destination' => $icu->name,
                'mode' => $index % 2 === 0 ? 'critical_care' : 'bls',
                'service' => 'Critical Care Transfer',
                'team' => null,
                'vendor' => $ambulance,
                'risk_flags' => ['oxygen', 'continuous_monitoring'],
                'statuses' => ['requested', 'accepted', 'assigned', 'dispatched', 'en_route', 'arrived_destination', 'handoff_started'],
            ]),
            'discharge' => array_merge($base, [
                'destination' => $index % 2 === 0 ? 'Main Lobby Discharge Lounge' : 'Patient Residence',
                'mode' => $index % 2 === 0 ? 'wheelchair' : 'nemt',
                'service' => 'Discharge',
                'team' => null,
                'vendor' => $nemt,
                'risk_flags' => $index % 3 === 0 ? ['mobility_assist'] : [],
                'statuses' => ['requested', 'accepted', 'assigned', 'dispatched', 'arrived_pickup', 'patient_ready', 'en_route'],
            ]),
            'ems' => array_merge($base, [
                'origin' => 'County EMS Zone '.(($index % 6) + 1),
                'destination' => $ed->name,
                'mode' => $index % 2 === 0 ? 'als' : 'bls',
                'service' => 'Emergency',
                'team' => null,
                'vendor' => $ambulance,
                'risk_flags' => ['prehospital_handoff', 'continuous_monitoring'],
                'statuses' => ['accepted', 'assigned', 'dispatched', 'en_route', 'arrived_destination', 'handoff_started'],
            ]),
            'care_transition' => array_merge($base, [
                'destination' => $postAcute[$index % max(1, count($postAcute))] ?? 'Skilled Nursing Facility',
                'mode' => $index % 2 === 0 ? 'nemt' : 'stretcher',
                'service' => 'Care Management',
                'team' => null,
                'vendor' => $index % 2 === 0 ? $nemt : $ambulance,
                'risk_flags' => ['transition_packet', 'medication_reconciliation'],
                'statuses' => ['requested', 'accepted', 'assigned', 'dispatched', 'arrived_pickup', 'patient_ready', 'en_route', 'handoff_started'],
            ]),
            default => $base,
        };
    }

    /**
     * @param  array<string,mixed>  $scenario
     */
    private function createTransport(array $scenario, string $status, Carbon $requestedAt, Carbon $neededAt, string $key): int
    {
        $timeline = $this->transportTimeline($status, $requestedAt, str_contains($key, 'history:') && crc32($key) % 7 === 0);
        $lastAt = collect($timeline)->max(fn (array $event): int => $event['at']->getTimestamp());
        $terminalAt = in_array($status, ['completed', 'canceled', 'failed'], true) ? Carbon::createFromTimestamp($lastAt) : null;
        $assignedAt = collect($timeline)->firstWhere('type', 'transport.assigned')['at'] ?? null;
        $dispatchedAt = collect($timeline)->firstWhere('type', 'transport.dispatched')['at'] ?? null;
        $handoff = str_contains($status, 'handoff') || $status === 'completed'
            ? ['sending_unit' => $scenario['origin'], 'receiving_location' => $scenario['destination'], 'identity_verified' => true]
            : [];
        $handoffRequired = in_array($scenario['type'], (array) config('transport.handoff_required_types', []), true);

        $request = TransportRequest::create([
            'request_uuid' => $this->uuid("transport:{$key}:{$requestedAt->toISOString()}"),
            'request_type' => $scenario['type'],
            'priority' => $scenario['priority'],
            'status' => $status,
            'patient_ref' => 'SYN-TX-'.strtoupper(substr(sha1($key), 0, 8)),
            'encounter_ref' => 'SYN-ENC-'.strtoupper(substr(sha1('encounter:'.$key), 0, 8)),
            'origin' => $scenario['origin'],
            'destination' => $scenario['destination'],
            'transport_mode' => $scenario['mode'],
            'clinical_service' => $scenario['service'],
            'requested_by' => self::OWNER,
            'requested_at' => $requestedAt,
            'needed_at' => $neededAt,
            'assigned_at' => $assignedAt,
            'dispatched_at' => $dispatchedAt,
            'completed_at' => $terminalAt,
            'assigned_team' => $scenario['team'],
            'assigned_vendor' => $scenario['vendor'],
            'external_system' => 'synthetic-operations-scenario',
            'external_id' => self::SCENARIO_ID.':'.$key,
            'segments' => [[
                'sequence' => 1,
                'origin' => $scenario['origin'],
                'destination' => $scenario['destination'],
                'mode' => $scenario['mode'],
            ]],
            'risk_flags' => $scenario['risk_flags'],
            'handoff' => $handoff,
            'handoff_required' => $handoffRequired,
            'lifecycle_version' => count($timeline),
            'metadata' => $this->metadata([
                'resource_type' => $scenario['vendor'] ? 'external_vendor' : 'internal_team',
                'equipment_check_required' => in_array($scenario['mode'], ['stretcher', 'bed', 'critical_care', 'als'], true),
                'identity_and_destination_verification' => true,
            ]),
            'is_deleted' => false,
        ]);

        foreach ($timeline as $ordinal => $event) {
            TransportEvent::create([
                'event_uuid' => $this->uuid("transport-event:{$key}:{$ordinal}:{$event['type']}"),
                'transport_request_id' => $request->transport_request_id,
                'event_type' => $event['type'],
                'from_status' => $event['from'],
                'to_status' => $event['to'],
                'payload' => array_merge($this->metadata(), $event['payload']),
                'source' => self::OWNER,
                'occurred_at' => $event['at'],
                'created_at' => $event['at'],
            ]);
        }

        $this->createTransportGovernanceArtifacts(
            $request,
            $scenario,
            $timeline,
            $assignedAt,
            $terminalAt,
            $key,
        );

        return count($timeline);
    }

    /**
     * Keep the deterministic scenario compatible with the governed runtime tables.
     * Synthetic reset remains request-owned; FK cascades remove these rows before
     * the next stable-key projection.
     *
     * @param  array<string,mixed>  $scenario
     * @param  list<array{type:string,from:?string,to:string,at:Carbon,payload:array<string,mixed>}>  $timeline
     */
    private function createTransportGovernanceArtifacts(
        TransportRequest $request,
        array $scenario,
        array $timeline,
        ?Carbon $assignedAt,
        ?Carbon $terminalAt,
        string $key,
    ): void {
        if (! Schema::hasTable('prod.transport_resources') || ! Schema::hasTable('prod.transport_assignments')) {
            return;
        }

        $resourceName = (string) ($scenario['vendor'] ?: $scenario['team']);
        if ($assignedAt !== null && $resourceName !== '') {
            $resourceType = $scenario['vendor'] ? 'vendor' : 'team';
            $configured = collect(config('transport.resources', []))->first(
                fn (array $resource): bool => mb_strtolower((string) $resource['name']) === mb_strtolower($resourceName),
            );
            $resourceKey = (string) ($configured['key'] ?? 'scenario-'.$resourceType.'-'.substr(hash('sha256', $resourceName), 0, 24));
            $resource = DB::table('prod.transport_resources')->where('resource_key', $resourceKey)->first();
            $busy = $resource ? (int) DB::table('prod.transport_assignments')
                ->where('transport_resource_id', $resource->transport_resource_id)
                ->where('status', 'active')
                ->whereNull('released_at')
                ->sum('capacity_units') : 0;
            $capacity = max(1, (int) ($configured['capacity'] ?? 1), $busy + ($terminalAt === null ? 1 : 0));
            $resourceValues = [
                'resource_type' => $resourceType,
                'display_name' => $resourceName,
                'capacity' => $capacity,
                'capabilities' => json_encode(array_values($configured['capabilities'] ?? [$scenario['mode']])),
                'metadata' => json_encode($this->metadata(['scenario_owned' => true])),
                'source' => self::OWNER,
                'is_active' => true,
                'updated_at' => now(),
            ];
            if ($resource) {
                DB::table('prod.transport_resources')
                    ->where('transport_resource_id', $resource->transport_resource_id)
                    ->update($resourceValues);
                $resourceId = (int) $resource->transport_resource_id;
            } else {
                $resourceId = (int) DB::table('prod.transport_resources')->insertGetId([
                    'resource_uuid' => $this->uuid("transport-resource:{$resourceKey}"),
                    'resource_key' => $resourceKey,
                    ...$resourceValues,
                    'created_at' => now(),
                ], 'transport_resource_id');
            }

            $assignmentStatus = $terminalAt === null ? 'active' : $request->status;
            DB::table('prod.transport_assignments')->insert([
                'assignment_uuid' => $this->uuid("transport-assignment:{$key}"),
                'transport_request_id' => $request->transport_request_id,
                'transport_resource_id' => $resourceId,
                'capacity_units' => 1,
                'status' => $assignmentStatus,
                'reserved_from' => $assignedAt,
                'released_at' => $terminalAt,
                'metadata' => json_encode($this->metadata(['scenario_owned' => true])),
                'created_at' => $assignedAt,
                'updated_at' => $terminalAt ?? $assignedAt,
            ]);
        }

        if (! $request->handoff_required || ! Schema::hasTable('prod.transport_handoff_evidence')) {
            return;
        }
        $handoffAt = collect($timeline)->firstWhere('type', 'transport.handoff_complete')['at'] ?? null;
        if (! $handoffAt instanceof Carbon) {
            return;
        }

        DB::table('prod.transport_handoff_evidence')->insert([
            'evidence_uuid' => $this->uuid("transport-handoff:{$key}"),
            'transport_request_id' => $request->transport_request_id,
            'handoff_to' => $scenario['destination'].' receiver',
            'receiver_role' => $scenario['type'] === 'ems' ? 'emergency_department_clinician' : 'receiving_clinician',
            'acceptance_status' => 'accepted',
            'accepted_at' => $handoffAt,
            'handoff_summary' => 'Synthetic governed handoff evidence.',
            'documents' => json_encode([]),
            'outstanding_risks' => json_encode([]),
            'created_at' => $handoffAt,
        ]);
    }

    /** @return list<array{type:string,from:?string,to:string,at:Carbon,payload:array<string,mixed>}> */
    private function transportTimeline(string $targetStatus, Carbon $requestedAt, bool $notReady): array
    {
        $steps = [
            ['transport.requested', null, 'requested', 0],
            ['transport.accepted', 'requested', 'accepted', 4],
            ['transport.assigned', 'accepted', 'assigned', 9],
            ['transport.dispatched', 'assigned', 'dispatched', 14],
            ['transport.arrived', 'dispatched', 'arrived_pickup', 23],
            ['transport.patient_ready', 'arrived_pickup', 'patient_ready', 27],
            ['transport.picked_up', 'patient_ready', 'picked_up', 31],
            ['transport.en_route', 'picked_up', 'en_route', 34],
            ['transport.arrived_destination', 'en_route', 'arrived_destination', 47],
            ['transport.handoff_started', 'arrived_destination', 'handoff_started', 50],
            ['transport.handoff_complete', 'handoff_started', 'handoff_complete', 55],
            ['transport.completed', 'handoff_complete', 'completed', 58],
        ];

        if ($targetStatus === 'canceled') {
            $steps = array_slice($steps, 0, 3);
            $steps[] = ['transport.canceled', 'assigned', 'canceled', 16];
        } else {
            $targetIndex = collect($steps)->search(fn (array $step): bool => $step[2] === $targetStatus);
            $steps = array_slice($steps, 0, $targetIndex === false ? 1 : $targetIndex + 1);
        }

        $events = [];
        foreach ($steps as [$type, $from, $to, $minute]) {
            if ($notReady && $to === 'patient_ready') {
                $from = 'patient_not_ready';
            }
            $events[] = [
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'at' => $requestedAt->copy()->addMinutes($minute + ($notReady && $minute >= 27 ? 18 : 0)),
                'payload' => [],
            ];
            if ($notReady && $to === 'arrived_pickup') {
                $events[] = [
                    'type' => 'transport.not_ready',
                    'from' => 'arrived_pickup',
                    'to' => 'patient_not_ready',
                    'at' => $requestedAt->copy()->addMinutes(25),
                    'payload' => ['reason_code' => 'clinical_preparation', 'not_ready_delay_min' => 18],
                ];
            }
        }

        usort($events, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $events;
    }

    private function shiftStart(Carbon $anchor, string $shift): Carbon
    {
        return $anchor->copy()->startOfDay()->addHours(['day' => 7, 'evening' => 15, 'night' => 23][$shift]);
    }

    /** @return array<string,mixed> */
    private function metadata(array $extra = []): array
    {
        return array_merge([
            'scenario_id' => self::SCENARIO_ID,
            'data_origin' => 'synthetic',
            'facility_key' => $this->hospital->facilityKey(),
        ], $extra);
    }

    private function uuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::SCENARIO_ID.':'.$key)->toString();
    }
}
