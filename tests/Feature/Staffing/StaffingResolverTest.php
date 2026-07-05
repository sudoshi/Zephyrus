<?php

namespace Tests\Feature\Staffing;

use App\Models\Reference\StaffRole;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Staffing\ServiceLineRoleResolver;
use App\Services\Staffing\Support\RawStaffRecord;
use App\Services\Staffing\Support\ResolvedAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 resolution engine (§5): layered precedence (override > rule > heuristic >
 * unmatched), confidence + evidence stamping, multi-membership, regulated flagging,
 * and registry-safe service-line validation.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§5)
 */
class StaffingResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
        ServiceLineNormalizer::flush();
    }

    private function resolver(): ServiceLineRoleResolver
    {
        return app(ServiceLineRoleResolver::class);
    }

    /** @return array<string,bool> */
    private function regulatedRoles(): array
    {
        return StaffRole::where('is_regulated', true)->pluck('role_code')
            ->mapWithKeys(fn (string $c): array => [$c => true])->all();
    }

    private function record(array $overrides = []): RawStaffRecord
    {
        return RawStaffRecord::fromArray('HRIS', array_merge(['external_id' => '1'], $overrides));
    }

    public function test_override_wins_with_full_confidence(): void
    {
        $record = $this->record(['job_title' => 'Hospitalist']); // would otherwise heuristic-match
        $overrides = ['HRIS:1' => [['service_line_code' => 'critical_care', 'role_code' => 'intensivist']]];

        $resolved = $this->resolver()->resolve($record, [], $overrides, $this->regulatedRoles());

        $this->assertCount(1, $resolved);
        $this->assertSame('critical_care', $resolved[0]->serviceLineCode);
        $this->assertSame('intensivist', $resolved[0]->roleCode);
        $this->assertSame(1.00, $resolved[0]->confidence);
        $this->assertSame('override', $resolved[0]->resolutionSource);
        $this->assertTrue($resolved[0]->primary);
    }

    public function test_deterministic_rule_matches_by_field(): void
    {
        $rules = [[
            'staff_mapping_rule_id' => 10,
            'match_field' => 'cost_center',
            'match_operator' => 'equals',
            'match_value' => 'CC100',
            'target_service_line_code' => 'critical_care',
            'target_role_code' => 'intensivist',
            'confidence' => 0.95,
        ]];
        $record = $this->record(['cost_center' => 'CC100', 'job_title' => 'Physician']);

        $resolved = $this->resolver()->resolve($record, $rules, [], $this->regulatedRoles());

        $this->assertCount(1, $resolved);
        $this->assertSame('rule', $resolved[0]->resolutionSource);
        $this->assertEquals(0.95, $resolved[0]->confidence);
        $this->assertSame(10, $resolved[0]->evidence['rule_id']);
        $this->assertSame('CC100', $resolved[0]->evidence['matched_value']);
    }

    public function test_multiple_rules_yield_multi_membership_with_one_primary(): void
    {
        $rules = [
            ['staff_mapping_rule_id' => 1, 'match_field' => 'specialty', 'match_operator' => 'equals', 'match_value' => 'Hospital Medicine', 'target_service_line_code' => 'hospital_medicine', 'target_role_code' => 'hospitalist', 'confidence' => 0.95],
            ['staff_mapping_rule_id' => 2, 'match_field' => 'cost_center', 'match_operator' => 'equals', 'match_value' => 'ICU', 'target_service_line_code' => 'critical_care', 'target_role_code' => 'intensivist', 'confidence' => 0.92],
        ];
        $record = $this->record(['specialty' => 'Hospital Medicine', 'cost_center' => 'ICU']);

        $resolved = $this->resolver()->resolve($record, $rules, [], $this->regulatedRoles());

        $this->assertCount(2, $resolved);
        $primaries = array_filter($resolved, fn (ResolvedAssignment $a): bool => $a->primary);
        $this->assertCount(1, $primaries, 'exactly one primary membership');
        $this->assertSame('hospital_medicine', $resolved[0]->serviceLineCode);
    }

    public function test_heuristic_fallback_from_job_title(): void
    {
        $record = $this->record(['job_title' => 'Charge Nurse, 4 West']);

        $resolved = $this->resolver()->resolve($record, [], [], $this->regulatedRoles());

        $this->assertCount(1, $resolved);
        $this->assertSame('heuristic', $resolved[0]->resolutionSource);
        $this->assertSame('charge_nurse', $resolved[0]->roleCode);
        $this->assertLessThan(0.90, $resolved[0]->confidence);
    }

    public function test_unmatched_returns_empty(): void
    {
        $record = $this->record(['job_title' => 'Zorbstopper Wrangler', 'specialty' => 'Nonsense']);

        $this->assertSame([], $this->resolver()->resolve($record, [], [], $this->regulatedRoles()));
    }

    public function test_regulated_role_is_flagged(): void
    {
        $rules = [[
            'staff_mapping_rule_id' => 5,
            'match_field' => 'specialty',
            'match_operator' => 'equals',
            'match_value' => 'Trauma Surgery',
            'target_service_line_code' => 'trauma_acute_care_surgery',
            'target_role_code' => 'trauma_surgeon',
            'confidence' => 0.97,
        ]];
        $record = $this->record(['specialty' => 'Trauma Surgery']);

        $resolved = $this->resolver()->resolve($record, $rules, [], $this->regulatedRoles());

        $this->assertCount(1, $resolved);
        $this->assertTrue($resolved[0]->regulated);
    }

    public function test_rule_targeting_an_unknown_service_line_is_skipped(): void
    {
        $rules = [[
            'staff_mapping_rule_id' => 7,
            'match_field' => 'department',
            'match_operator' => 'equals',
            'match_value' => 'X',
            'target_service_line_code' => 'not_a_real_service_line',
            'target_role_code' => 'staff_nurse',
            'confidence' => 0.99,
        ]];
        $record = $this->record(['department' => 'X']); // no heuristic hit either

        // The bad rule is dropped; nothing else matches -> unmatched.
        $this->assertSame([], $this->resolver()->resolve($record, $rules, [], $this->regulatedRoles()));
    }

    public function test_rule_service_line_alias_is_canonicalized(): void
    {
        $rules = [[
            'staff_mapping_rule_id' => 8,
            'match_field' => 'specialty',
            'match_operator' => 'contains',
            'match_value' => 'cardio',
            'target_service_line_code' => 'cardiology', // legacy alias of cardiovascular
            'target_role_code' => 'cardiologist',
            'confidence' => 0.93,
        ]];
        $record = $this->record(['specialty' => 'Interventional Cardiology']);

        $resolved = $this->resolver()->resolve($record, $rules, [], $this->regulatedRoles());

        $this->assertCount(1, $resolved);
        $this->assertSame('cardiovascular', $resolved[0]->serviceLineCode);
    }
}
