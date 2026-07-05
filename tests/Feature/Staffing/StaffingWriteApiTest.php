<?php

namespace Tests\Feature\Staffing;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMappingRule;
use App\Models\Org\StaffMember;
use App\Models\User;
use App\Services\Deployment\ServiceLineNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F4 (§8): the Staffing Alignment Wizard write API. Covers RBAC gating
 * (manageDeploymentConfig), source creation, dry-run stage+bucket, the review->commit
 * loop with per-member decisions, rule promotion + re-resolve shrinking the queue,
 * additive provisioning, soft-deactivation, and coverage — all over HTTP.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§8, §10, §11)
 */
class StaffingWriteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
        ServiceLineNormalizer::flush();
    }

    private function admin(string $role = 'superuser'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false]);
    }

    private function source(): StaffingSource
    {
        return StaffingSource::create([
            'source_key' => 'CSV_UPLOAD',
            'connector_type' => 'manual',
            'transport' => 'file_upload',
            'mapping_template' => [],
        ]);
    }

    private function csv(): string
    {
        return implode("\n", [
            'external_id,display_name,email,department,job_title,specialty',
            'EMP1,Ada Intensivist,ada@hospital.org,Critical Care,Physician,',
            'EMP2,Ben Hospitalist,,,Hospitalist,',
            'EMP3,Cy Unknown,,,Zorbstopper Wrangler,',
        ]);
    }

    // ── RBAC ────────────────────────────────────────────────────────────────

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/deployment/staffing/sources')->assertUnauthorized();
    }

    public function test_frontline_role_is_forbidden(): void
    {
        $this->actingAs($this->admin('user'))
            ->getJson('/api/deployment/staffing/sources')
            ->assertForbidden();
    }

    public function test_plain_admin_is_forbidden_writes_are_narrower_than_reads(): void
    {
        // 'admin' can read the console but NOT write staffing config.
        $this->actingAs($this->admin('admin'))
            ->getJson('/api/deployment/staffing/sources')
            ->assertForbidden();
    }

    public function test_ops_leader_may_list_sources(): void
    {
        $this->actingAs($this->admin('ops-leader'))
            ->getJson('/api/deployment/staffing/sources')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);
    }

    // ── Source ──────────────────────────────────────────────────────────────

    public function test_create_source_returns_no_secrets(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/deployment/staffing/sources', [
                'source_key' => 'CSV_UPLOAD',
                'display_name' => 'Manual CSV',
                'connector_type' => 'manual',
                'transport' => 'file_upload',
                'default_facility_key' => 'SUMMIT_REGIONAL',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_key', 'CSV_UPLOAD')
            ->assertJsonPath('data.default_facility_key', 'SUMMIT_REGIONAL');

        $body = $response->json('data');
        $this->assertArrayNotHasKey('integration_source_id', $body);
        $this->assertArrayNotHasKey('metadata', $body);
    }

    public function test_source_key_must_be_upper_snake(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/deployment/staffing/sources', [
                'source_key' => 'bad key',
                'connector_type' => 'manual',
                'transport' => 'file_upload',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_key');
    }

    // ── Import dry-run ────────────────────────────────────────────────────────

    public function test_import_dry_run_stages_and_buckets(): void
    {
        $source = $this->source();
        StaffMappingRule::create([
            'match_field' => 'department', 'match_operator' => 'equals', 'match_value' => 'Critical Care',
            'target_service_line_code' => 'critical_care', 'target_role_code' => 'intensivist', 'confidence' => 0.95,
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/deployment/staffing/imports', [
                'source_id' => $source->staffing_source_id,
                'facility_key' => 'SUMMIT_REGIONAL',
                'csv' => $this->csv(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.run.status', 'resolved')
            ->assertJsonPath('data.run.counts.total', 3)
            ->assertJsonPath('data.run.counts.auto_approved', 1)
            ->assertJsonPath('data.run.counts.needs_review', 1)
            ->assertJsonPath('data.run.counts.unmatched', 1);

        // Dry run: members staged, no assignments written.
        $this->assertSame(3, StaffMember::count());
        $this->assertSame(0, StaffAssignment::count());

        $items = $response->json('data.staged.items');
        $this->assertCount(3, $items);
        $auto = collect($items)->firstWhere('bucket', 'auto_approved');
        $this->assertSame('critical_care', $auto['proposed'][0]['service_line_code']);
        $this->assertNull($auto['decision']);
    }

    // ── Review -> commit loop ─────────────────────────────────────────────────

    public function test_review_edit_then_commit_writes_the_decided_assignment(): void
    {
        $source = $this->source();
        $admin = $this->admin();

        $staged = $this->actingAs($admin)
            ->postJson('/api/deployment/staffing/imports', [
                'source_id' => $source->staffing_source_id,
                'facility_key' => 'SUMMIT_REGIONAL',
                'csv' => $this->csv(),
            ])->json('data');

        $runId = $staged['run']['staff_import_run_id'];
        $unmatched = collect($staged['staged']['items'])->firstWhere('bucket', 'unmatched');

        // Manually assign the unmatched person.
        $this->actingAs($admin)
            ->patchJson("/api/deployment/staffing/imports/{$runId}/reviews/{$unmatched['staff_member_id']}", [
                'action' => 'edit',
                'assignments' => [
                    ['service_line_code' => 'hospital_medicine', 'role_code' => 'case_manager', 'primary' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.item.decision.action', 'edit');

        $this->actingAs($admin)
            ->postJson("/api/deployment/staffing/imports/{$runId}/commit")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'committed');

        // auto_approved has no rule here (no proposals), so only the edited member commits.
        $edited = StaffAssignment::where('staff_member_id', $unmatched['staff_member_id'])->firstOrFail();
        $this->assertSame('hospital_medicine', $edited->service_line_code);
        $this->assertSame('case_manager', $edited->role_code);
        $this->assertSame('override', $edited->resolution_source);
        $this->assertTrue($edited->primary_flag);
    }

    public function test_rejected_member_is_not_committed(): void
    {
        $source = $this->source();
        $admin = $this->admin();
        StaffMappingRule::create([
            'match_field' => 'department', 'match_operator' => 'equals', 'match_value' => 'Critical Care',
            'target_service_line_code' => 'critical_care', 'target_role_code' => 'intensivist', 'confidence' => 0.95,
        ]);

        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL', 'csv' => $this->csv(),
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];
        $auto = collect($staged['staged']['items'])->firstWhere('bucket', 'auto_approved');

        // Reject the otherwise auto-approved member.
        $this->actingAs($admin)->patchJson(
            "/api/deployment/staffing/imports/{$runId}/reviews/{$auto['staff_member_id']}",
            ['action' => 'reject'],
        )->assertOk();

        $this->actingAs($admin)->postJson("/api/deployment/staffing/imports/{$runId}/commit")->assertOk();

        $this->assertSame(0, StaffAssignment::where('staff_member_id', $auto['staff_member_id'])->count());
    }

    public function test_commit_is_guarded_against_double_commit(): void
    {
        $source = $this->source();
        $admin = $this->admin();
        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL', 'csv' => $this->csv(),
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];

        $this->actingAs($admin)->postJson("/api/deployment/staffing/imports/{$runId}/commit")->assertOk();
        $this->actingAs($admin)->postJson("/api/deployment/staffing/imports/{$runId}/commit")->assertStatus(409);
    }

    // ── Rule promotion + re-resolve ──────────────────────────────────────────

    public function test_rule_promotion_then_reresolve_shrinks_the_queue(): void
    {
        $source = $this->source();
        $admin = $this->admin();

        // A single heuristic-only person (job title -> hospitalist, confidence < 0.90).
        $csv = "external_id,display_name,job_title\nEMP2,Ben Hospitalist,Hospitalist\n";

        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL', 'csv' => $csv,
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];
        $this->assertSame(1, $staged['run']['counts']['needs_review']); // the hospitalist (heuristic)
        $this->assertSame(0, $staged['run']['counts']['auto_approved']);

        // Promote a deterministic rule for the hospitalist's job title.
        $this->actingAs($admin)->postJson('/api/deployment/staffing/rules', [
            'staffing_source_id' => $source->staffing_source_id,
            'match_field' => 'job_title', 'match_operator' => 'equals', 'match_value' => 'Hospitalist',
            'target_service_line_code' => 'hospital_medicine', 'target_role_code' => 'hospitalist', 'confidence' => 0.95,
        ])->assertCreated();

        $reresolved = $this->actingAs($admin)
            ->postJson("/api/deployment/staffing/imports/{$runId}/resolve")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $reresolved['run']['counts']['needs_review'], 'the rule now auto-approves the hospitalist');
        $this->assertSame(1, $reresolved['run']['counts']['auto_approved']);
    }

    public function test_reresolve_preserves_prior_decisions(): void
    {
        $source = $this->source();
        $admin = $this->admin();
        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL', 'csv' => $this->csv(),
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];
        $unmatched = collect($staged['staged']['items'])->firstWhere('bucket', 'unmatched');

        $this->actingAs($admin)->patchJson(
            "/api/deployment/staffing/imports/{$runId}/reviews/{$unmatched['staff_member_id']}",
            ['action' => 'defer', 'note' => 'follow up'],
        )->assertOk();

        $reresolved = $this->actingAs($admin)->postJson("/api/deployment/staffing/imports/{$runId}/resolve")->json('data');
        $stillDeferred = collect($reresolved['staged']['items'])->firstWhere('staff_member_id', $unmatched['staff_member_id']);
        $this->assertSame('defer', $stillDeferred['decision']['action']);
    }

    // ── Provisioning + deactivation + coverage ───────────────────────────────

    public function test_commit_provisions_linked_account_additively(): void
    {
        $account = User::factory()->create([
            'email' => 'ada@hospital.org', 'role' => 'user', 'is_active' => false, 'must_change_password' => true,
        ]);
        $source = $this->source();
        $admin = $this->admin();
        StaffMappingRule::create([
            'match_field' => 'department', 'match_operator' => 'equals', 'match_value' => 'Critical Care',
            'target_service_line_code' => 'critical_care', 'target_role_code' => 'intensivist', 'confidence' => 0.95,
        ]);

        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL', 'csv' => $this->csv(),
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];

        $this->actingAs($admin)
            ->postJson("/api/deployment/staffing/imports/{$runId}/commit")
            ->assertOk()
            ->assertJsonPath('data.summary.provisioned', 1);

        $fresh = $account->fresh();
        $this->assertSame('rtdc', $fresh->workflow_preference);
        $this->assertTrue($fresh->is_active);
        $this->assertSame('user', $fresh->role, 'auth role of record is never escalated by the API');
        $this->assertTrue($fresh->must_change_password);
    }

    public function test_deactivate_decision_soft_deactivates_the_member(): void
    {
        $admin = $this->admin();
        $member = StaffMember::create([
            'staff_key' => 'CSV_UPLOAD:GONE', 'source_system' => 'CSV_UPLOAD', 'external_id' => 'GONE',
            'display_name' => 'Departed Dan', 'is_active' => true,
        ]);
        StaffAssignment::create([
            'staff_member_id' => $member->staff_member_id, 'facility_key' => 'SUMMIT_REGIONAL',
            'service_line_code' => 'critical_care', 'role_code' => 'staff_nurse', 'primary_flag' => true,
            'confidence' => 0.9, 'resolution_source' => 'rule', 'review_status' => 'source_verified',
            'evidence' => ['source' => 'rule'], 'is_active' => true,
        ]);
        $source = $this->source();

        // A roster that no longer lists GONE makes the still-present member 'departed'.
        $staged = $this->actingAs($admin)->postJson('/api/deployment/staffing/imports', [
            'source_id' => $source->staffing_source_id, 'facility_key' => 'SUMMIT_REGIONAL',
            'csv' => "external_id,display_name,job_title\nKEEP1,Kay Keeper,Hospitalist\n",
        ])->json('data');
        $runId = $staged['run']['staff_import_run_id'];

        $this->actingAs($admin)->patchJson(
            "/api/deployment/staffing/imports/{$runId}/reviews/{$member->staff_member_id}",
            ['action' => 'deactivate'],
        )->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/deployment/staffing/imports/{$runId}/commit")
            ->assertOk()
            ->assertJsonPath('data.summary.deactivated', 1);

        $this->assertFalse($member->fresh()->is_active);
        $this->assertFalse(StaffAssignment::where('staff_member_id', $member->staff_member_id)->firstOrFail()->is_active);
    }

    public function test_coverage_endpoint_reports_for_a_facility(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/deployment/staffing/coverage?facility=SUMMIT_REGIONAL')
            ->assertOk()
            ->assertJsonPath('data.facility_key', 'SUMMIT_REGIONAL')
            ->assertJsonStructure(['data' => ['summary' => ['units_total', 'units_staffed', 'units_unstaffed']]]);
    }

    public function test_reference_endpoint_lists_service_lines_and_roles(): void
    {
        $reference = $this->actingAs($this->admin())
            ->getJson('/api/deployment/staffing/reference')
            ->assertOk()
            ->assertJsonStructure(['data' => ['service_lines' => [['code', 'name']], 'roles' => [['role_code', 'display_name', 'is_regulated']]]])
            ->json('data');

        $this->assertNotEmpty($reference['service_lines']);
        $this->assertNotEmpty($reference['roles']);
        // A regulated role is flagged so the wizard can warn on it.
        $this->assertTrue(collect($reference['roles'])->contains(fn ($r): bool => $r['is_regulated'] === true));
    }
}
