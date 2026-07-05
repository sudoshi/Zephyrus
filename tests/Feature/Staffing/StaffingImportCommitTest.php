<?php

namespace Tests\Feature\Staffing;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMappingRule;
use App\Models\Org\StaffMember;
use App\Models\Unit;
use App\Models\User;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Staffing\Connectors\CsvUploadConnector;
use App\Services\Staffing\Connectors\FhirPractitionerConnector;
use App\Services\Staffing\CoverageService;
use App\Services\Staffing\StaffImportOrchestrator;
use App\Services\Staffing\Support\PullWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7 orchestrator: dry-run -> bucket -> commit; FK-valid + evidenced assignments;
 * idempotent re-sync; regulated roles never auto-committed; rule promotion shrinks the
 * queue; account provisioning is additive; termination sweep respects grace; CSV + FHIR
 * both stage; coverage reports staffed/unstaffed units.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6, §10, §14)
 */
class StaffingImportCommitTest extends TestCase
{
    use RefreshDatabase;

    private StaffingSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
        ServiceLineNormalizer::flush();

        $this->source = StaffingSource::create([
            'source_key' => 'CSV_UPLOAD',
            'connector_type' => 'manual',
            'transport' => 'file_upload',
            'mapping_template' => [],
        ]);
    }

    private function orchestrator(): StaffImportOrchestrator
    {
        return app(StaffImportOrchestrator::class);
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(array $rows): CsvUploadConnector
    {
        return new CsvUploadConnector('CSV_UPLOAD', $rows);
    }

    private function rule(string $field, string $value, string $serviceLine, string $role, float $confidence = 0.95, ?string $unitHint = null): void
    {
        StaffMappingRule::create([
            'match_field' => $field,
            'match_operator' => 'equals',
            'match_value' => $value,
            'target_service_line_code' => $serviceLine,
            'target_role_code' => $role,
            'target_unit_hint' => $unitHint,
            'confidence' => $confidence,
        ]);
    }

    private function seedUnit(string $abbr, string $serviceLine, string $acuity = 'icu'): Unit
    {
        $now = Carbon::parse('2026-07-05 00:00:00');
        $spaceId = DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => $abbr,
            'space_name' => $abbr,
            'space_category' => 'unit',
            'service_line_code' => $serviceLine,
            'acuity_level' => $acuity,
            'facility_key' => 'SUMMIT_REGIONAL',
            'status' => 'active',
            'attributes' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'facility_space_id');

        return Unit::create([
            'name' => $abbr,
            'abbreviation' => $abbr,
            'type' => 'icu',
            'staffed_bed_count' => 8,
            'ratio_floor' => 2,
            'facility_space_id' => $spaceId,
            'is_deleted' => false,
        ]);
    }

    public function test_csv_stages_members_and_buckets_by_confidence(): void
    {
        $this->rule('department', 'Critical Care', 'critical_care', 'intensivist');

        $result = $this->orchestrator()->run($this->source, $this->csv([
            ['external_id' => 'EMP1', 'display_name' => 'Ada Intensivist', 'department' => 'Critical Care', 'job_title' => 'MD'],
            ['external_id' => 'EMP2', 'display_name' => 'Ben Hospitalist', 'job_title' => 'Hospitalist'],
            ['external_id' => 'EMP3', 'display_name' => 'Cy Unknown', 'job_title' => 'Zorbstopper Wrangler'],
        ]), 'SUMMIT_REGIONAL', PullWindow::full());

        $counts = $result->counts();
        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['auto_approved']);
        $this->assertSame(1, $counts['needs_review']);
        $this->assertSame(1, $counts['unmatched']);

        // staff_members are staged even on a dry run; no assignments yet.
        $this->assertSame(3, StaffMember::count());
        $this->assertSame(0, StaffAssignment::count());
        $this->assertSame('resolved', $result->run->fresh()->status);
    }

    public function test_commit_writes_fk_valid_assignments_and_is_idempotent(): void
    {
        $this->rule('department', 'Critical Care', 'critical_care', 'intensivist');
        $connector = $this->csv([
            ['external_id' => 'EMP1', 'display_name' => 'Ada', 'department' => 'Critical Care'],
        ]);

        $result = $this->orchestrator()->run($this->source, $connector, 'SUMMIT_REGIONAL', PullWindow::full());
        $summary = $this->orchestrator()->commit($result, 'SUMMIT_REGIONAL');

        $this->assertSame(1, $summary['assignments']);
        $assignment = StaffAssignment::firstOrFail();
        $this->assertSame('critical_care', $assignment->service_line_code);
        $this->assertSame('intensivist', $assignment->role_code);
        $this->assertSame('SUMMIT_REGIONAL', $assignment->facility_key);
        $this->assertEquals(0.95, (float) $assignment->confidence);
        $this->assertSame('source_verified', $assignment->review_status);
        $this->assertTrue($assignment->primary_flag);
        $this->assertNotEmpty($assignment->evidence);

        // Re-sync + re-commit: no duplicate, one-primary index holds.
        $again = $this->orchestrator()->run($this->source, $this->csv([
            ['external_id' => 'EMP1', 'display_name' => 'Ada', 'department' => 'Critical Care'],
        ]), 'SUMMIT_REGIONAL', PullWindow::full());
        $this->orchestrator()->commit($again, 'SUMMIT_REGIONAL');

        $this->assertSame(1, StaffAssignment::count());
        $this->assertSame(1, StaffAssignment::where('primary_flag', true)->count());
    }

    public function test_regulated_role_routes_to_review_and_is_not_auto_committed(): void
    {
        $this->rule('specialty', 'Trauma Surgery', 'trauma_acute_care_surgery', 'trauma_surgeon', 0.98);

        $result = $this->orchestrator()->run($this->source, $this->csv([
            ['external_id' => 'DOC1', 'display_name' => 'Dr Trauma', 'specialty' => 'Trauma Surgery'],
        ]), 'SUMMIT_REGIONAL', PullWindow::full());

        $this->assertSame(1, $result->counts()['needs_review']);
        $this->assertSame(0, $result->counts()['auto_approved']);

        $this->orchestrator()->commit($result, 'SUMMIT_REGIONAL'); // auto_approved bucket only
        $this->assertSame(0, StaffAssignment::count(), 'a regulated role is never auto-committed');
    }

    public function test_commit_provisions_linked_account_additively(): void
    {
        $account = User::factory()->create([
            'email' => 'ada@hospital.org',
            'role' => 'user',
            'is_active' => false,
            'must_change_password' => true,
        ]);
        $this->rule('department', 'Critical Care', 'critical_care', 'intensivist');

        $result = $this->orchestrator()->run($this->source, $this->csv([
            ['external_id' => 'EMP1', 'display_name' => 'Ada', 'email' => 'ada@hospital.org', 'department' => 'Critical Care'],
        ]), 'SUMMIT_REGIONAL', PullWindow::full());

        // identity resolver linked the account.
        $this->assertSame($account->id, StaffMember::firstOrFail()->user_id);

        $summary = $this->orchestrator()->commit($result, 'SUMMIT_REGIONAL');
        $this->assertSame(1, $summary['provisioned']);

        $fresh = $account->fresh();
        $this->assertSame('rtdc', $fresh->workflow_preference); // intensivist default_workflow
        $this->assertTrue($fresh->is_active);
        $this->assertSame('user', $fresh->role, 'auth role of record is never escalated');
        $this->assertTrue($fresh->must_change_password);
    }

    public function test_fhir_bundle_stages_into_a_run(): void
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'entry' => [
                ['resource' => [
                    'resourceType' => 'Practitioner',
                    'id' => 'prac-1',
                    'name' => [['family' => 'Vega', 'given' => ['Rosa']]],
                    'identifier' => [['system' => 'http://hl7.org/fhir/sid/us-npi', 'value' => '1234567890']],
                    'telecom' => [['system' => 'email', 'value' => 'rosa@hospital.org']],
                ]],
                ['resource' => [
                    'resourceType' => 'PractitionerRole',
                    'practitioner' => ['reference' => 'Practitioner/prac-1'],
                    'specialty' => [['text' => 'Hospital Medicine']],
                    'code' => [['text' => 'Hospitalist']],
                ]],
            ],
        ];
        $connector = new FhirPractitionerConnector('EPIC_FHIR', $bundle);

        $result = $this->orchestrator()->run($this->source, $connector, 'SUMMIT_REGIONAL', PullWindow::full());

        $this->assertSame(1, $result->counts()['total']);
        $member = StaffMember::firstOrFail();
        $this->assertSame('1234567890', $member->npi);
        $this->assertSame('Rosa Vega', $member->display_name);
        // Hospital Medicine specialty -> heuristic hospitalist membership, routed to review.
        $this->assertSame(1, $result->counts()['needs_review']);
    }

    public function test_rule_promotion_shrinks_the_review_queue(): void
    {
        $rows = [['external_id' => 'RN1', 'display_name' => 'Ned Nurse', 'job_title' => 'Staff Nurse', 'department' => 'Renal']];

        $before = $this->orchestrator()->run($this->source, $this->csv($rows), 'SUMMIT_REGIONAL', PullWindow::full());
        $this->assertSame(1, $before->counts()['needs_review'], 'heuristic-only -> needs review');
        $this->assertSame(0, $before->counts()['auto_approved']);

        // Promote the reviewer's decision to a deterministic rule.
        $this->rule('department', 'Renal', 'renal_dialysis', 'staff_nurse', 0.95);

        $after = $this->orchestrator()->run($this->source, $this->csv($rows), 'SUMMIT_REGIONAL', PullWindow::full());
        $this->assertSame(0, $after->counts()['needs_review'], 'the rule now auto-approves it');
        $this->assertSame(1, $after->counts()['auto_approved']);
    }

    public function test_departed_member_is_swept_only_after_grace(): void
    {
        // A member unseen for 30 days, with a live assignment.
        $member = StaffMember::create([
            'staff_key' => 'CSV_UPLOAD:GONE', 'source_system' => 'CSV_UPLOAD', 'external_id' => 'GONE',
            'display_name' => 'Departed Dan', 'is_active' => true, 'last_seen_at' => Carbon::now()->subDays(30),
        ]);
        StaffAssignment::create([
            'staff_member_id' => $member->staff_member_id, 'facility_key' => 'SUMMIT_REGIONAL',
            'service_line_code' => 'critical_care', 'role_code' => 'staff_nurse', 'primary_flag' => true,
            'confidence' => 0.9, 'resolution_source' => 'rule', 'review_status' => 'source_verified',
            'evidence' => ['source' => 'rule'], 'is_active' => true,
        ]);

        // Grace not yet elapsed -> report only, no deactivation.
        $this->artisan('deployment:staffing-drift', ['source' => 'CSV_UPLOAD', '--grace' => 60, '--sweep' => true])
            ->assertExitCode(0);
        $this->assertTrue($member->fresh()->is_active);

        // Past grace -> swept (soft-deactivate member + assignments).
        $this->artisan('deployment:staffing-drift', ['source' => 'CSV_UPLOAD', '--grace' => 14, '--sweep' => true])
            ->assertExitCode(0);

        $this->assertFalse($member->fresh()->is_active);
        $this->assertSame('terminated', $member->fresh()->employment_status);
        $assignment = StaffAssignment::where('staff_member_id', $member->staff_member_id)->firstOrFail();
        $this->assertFalse($assignment->is_active);
        $this->assertFalse($assignment->primary_flag);
    }

    public function test_coverage_reports_staffed_and_unstaffed_units(): void
    {
        $micu = $this->seedUnit('MICU', 'critical_care');
        $this->seedUnit('SICU', 'critical_care'); // left unstaffed

        $this->rule('department', 'Critical Care', 'critical_care', 'intensivist', 0.95, unitHint: 'MICU');
        $result = $this->orchestrator()->run($this->source, $this->csv([
            ['external_id' => 'EMP1', 'display_name' => 'Ada', 'department' => 'Critical Care'],
        ]), 'SUMMIT_REGIONAL', PullWindow::full());
        $this->orchestrator()->commit($result, 'SUMMIT_REGIONAL');

        $this->assertSame($micu->unit_id, (int) StaffAssignment::firstOrFail()->unit_id);

        $report = app(CoverageService::class)->report('SUMMIT_REGIONAL');
        $this->assertSame(2, $report['summary']['units_total']);
        $this->assertSame(1, $report['summary']['units_staffed']);
        $this->assertSame(1, $report['summary']['units_unstaffed']);

        $unstaffed = array_column(app(CoverageService::class)->unstaffedUnits('SUMMIT_REGIONAL'), 'abbreviation');
        $this->assertContains('SICU', $unstaffed);
        $this->assertNotContains('MICU', $unstaffed);
    }
}
