<?php

namespace Tests\Feature\Authorization;

use App\Http\Middleware\RequireAdminScope;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Authorization\AdminScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $firstOrganization;

    private Organization $secondOrganization;

    private Facility $firstFacility;

    private Facility $secondFacility;

    private int $firstSourceId;

    private int $secondSourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $this->firstOrganization = $this->organization('SCOPE_IDN_ONE', 'Scope IDN One');
        $this->secondOrganization = $this->organization('SCOPE_IDN_TWO', 'Scope IDN Two');
        $this->firstFacility = $this->facility($this->firstOrganization, 'SCOPE_FACILITY_ONE', 'Scope Facility One');
        $this->secondFacility = $this->facility($this->secondOrganization, 'SCOPE_FACILITY_TWO', 'Scope Facility Two');
        $this->firstSourceId = $this->source($this->firstOrganization, $this->firstFacility, 'scope.source.one');
        $this->secondSourceId = $this->source($this->secondOrganization, $this->secondFacility, 'scope.source.two');
    }

    public function test_global_role_must_explicitly_select_scope_before_mutating(): void
    {
        $user = User::factory()->create(['role' => 'superuser']);

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$this->firstSourceId}", ['source_name' => 'Blocked Rename'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'admin_scope_required');

        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->firstOrganization->organization_id,
            'facility_id' => $this->firstFacility->facility_id,
            'source_id' => $this->firstSourceId,
            'return_path' => '/integrations?tab=sources',
        ])->assertRedirect('/integrations?tab=sources&organization_id='.(int) $this->firstOrganization->organization_id
            .'&facility_id='.(int) $this->firstFacility->facility_id.'&source_id='.$this->firstSourceId);

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$this->firstSourceId}", [
                'source_name' => 'Scoped Rename',
                'expected_configuration_version_id' => $this->configurationVersionId($this->firstSourceId),
                'change_reason' => 'Rename the source after selecting its exact administration scope.',
            ])
            ->assertOk()
            ->assertJsonPath('data.sourceName', 'Scoped Rename');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.scope.selected',
            'target_type' => 'admin_scope',
        ]);
    }

    public function test_explicit_grant_catalog_and_mutations_are_tenant_isolated(): void
    {
        $user = User::factory()->create(['role' => 'integration_admin']);
        UserAccessScope::create([
            'user_id' => $user->id,
            'facility_id' => $this->firstFacility->facility_id,
            'granted_by_user_id' => $user->id,
            'grant_reason' => 'Assigned to the first facility integration team.',
            'valid_from' => now()->subMinute(),
        ]);

        $catalog = app(AdminScopeService::class)->catalog($user);
        $this->assertSame([(int) $this->firstOrganization->organization_id], array_column($catalog['organizations'], 'id'));
        $this->assertSame([(int) $this->firstFacility->facility_id], array_column($catalog['facilities'], 'id'));
        $this->assertSame([$this->firstSourceId], array_column($catalog['sources'], 'id'));

        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->firstOrganization->organization_id,
            'facility_id' => $this->firstFacility->facility_id,
            'source_id' => $this->firstSourceId,
            'return_path' => '/integrations',
        ])->assertRedirect();

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$this->secondSourceId}", ['source_name' => 'Cross Tenant Rename'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'admin_source_scope_mismatch');
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->secondSourceId,
            'source_name' => 'scope.source.two',
        ]);

        $this->actingAs($user)->putJson('/admin/active-scope', [
            'organization_id' => $this->secondOrganization->organization_id,
            'facility_id' => $this->secondFacility->facility_id,
            'source_id' => $this->secondSourceId,
        ])->assertConflict()->assertJsonPath('error.code', 'admin_scope_access_revoked');
    }

    public function test_scope_is_revalidated_after_grant_revocation_and_account_switch(): void
    {
        $scopedUser = User::factory()->create(['role' => 'integration_admin']);
        $grant = UserAccessScope::create([
            'user_id' => $scopedUser->id,
            'facility_id' => $this->firstFacility->facility_id,
            'granted_by_user_id' => $scopedUser->id,
            'grant_reason' => 'Temporary integration administration assignment.',
            'valid_from' => now()->subMinute(),
        ]);
        $this->selectFirstScope($scopedUser);
        $grant->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $scopedUser->id,
            'revocation_reason' => 'Assignment ended.',
        ]);

        $this->actingAs($scopedUser)
            ->patchJson("/api/admin/integrations/sources/{$this->firstSourceId}", ['source_name' => 'Revoked Rename'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'admin_scope_required');
        $this->assertNull(session(AdminScopeService::SESSION_KEY));

        $firstGlobal = User::factory()->create(['role' => 'superuser']);
        $secondGlobal = User::factory()->create(['role' => 'superuser']);
        $this->selectFirstScope($firstGlobal);
        $this->actingAs($secondGlobal)
            ->patchJson("/api/admin/integrations/sources/{$this->firstSourceId}", ['source_name' => 'Leaked Rename'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'admin_scope_required');
    }

    public function test_database_trigger_rejects_mismatched_enterprise_keys(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('facility key does not match');
        $this->source(
            $this->firstOrganization,
            $this->firstFacility,
            'scope.source.mismatch',
            ['facility_key' => $this->secondFacility->facility_key],
        );
    }

    public function test_database_trigger_rejects_unscoped_live_sources(): void
    {
        $this->expectException(QueryException::class);
        DB::table('integration.sources')->insert([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'scope.source.unscoped-live',
            'source_name' => 'Unscoped Live Source',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'active',
            'go_live_status' => 'live',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_admin_integration_mutation_declares_its_exact_scope_boundary(): void
    {
        $expected = [
            'DELETE api/admin/integrations/sources/{source}' => ['source'],
            'DELETE api/admin/integrations/sources/{source}/credentials/{credential}' => ['source'],
            'DELETE api/admin/integrations/sources/{source}/endpoints/{endpoint}' => ['source'],
            'DELETE api/admin/integrations/sources/{source}/network-routes/{route}' => ['source'],
            'PATCH api/admin/integrations/sources/{source}' => ['source'],
            'PATCH api/admin/integrations/sources/{source}/credentials/{credential}' => ['source'],
            'PATCH api/admin/integrations/sources/{source}/endpoints/{endpoint}' => ['source'],
            'PATCH api/admin/integrations/sources/{source}/network-routes/{route}' => ['source'],
            'POST api/admin/integrations/enterprise/fhir/capability-discovery' => ['source'],
            'POST api/admin/integrations/enterprise/replays' => ['governed_change', 'source'],
            'POST api/admin/integrations/enterprise/replays/preview' => ['source'],
            'POST api/admin/integrations/enterprise/replays/requests' => ['source'],
            'POST api/admin/integrations/enterprise/writeback-drafts' => ['source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/decision' => ['governed_change'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/execute-source-activation' => ['governed_change'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/execute-source-activation-schedule' => ['governed_change'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/execute-source-configuration' => ['governed_change'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/credentials/{credential}/execute-rotation' => ['governed_change', 'source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-hold' => ['governed_change', 'source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-integrity-recovery' => ['governed_change', 'source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-purge' => ['governed_change', 'source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/payload-quarantines/{quarantine}/execute-purge' => ['governed_change', 'source'],
            'POST api/admin/integrations/governed-changes/{changeRequestUuid}/sources/{source}/payload-quarantines/{quarantine}/execute-release' => ['governed_change', 'source'],
            'POST api/admin/integrations/sources' => ['facility'],
            'POST api/admin/integrations/sources/{source}/activation-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/activation-window-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/activation-windows/{windowUuid}/cancel' => ['source'],
            'POST api/admin/integrations/sources/{source}/configuration-versions' => ['source'],
            'POST api/admin/integrations/sources/{source}/configuration-versions/{version}/application-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/credentials' => ['source'],
            'POST api/admin/integrations/sources/{source}/credentials/{credential}/rotation-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/credentials/{credential}/validations' => ['source'],
            'POST api/admin/integrations/sources/{source}/endpoints' => ['source'],
            'POST api/admin/integrations/sources/{source}/evidence' => ['source'],
            'POST api/admin/integrations/sources/{source}/fhir/poll' => ['source'],
            'POST api/admin/integrations/sources/{source}/health-check' => ['source'],
            'POST api/admin/integrations/sources/{source}/lifecycle-transitions' => ['source'],
            'POST api/admin/integrations/sources/{source}/network-routes' => ['source'],
            'POST api/admin/integrations/sources/{source}/network-routes/{route}/validations' => ['source'],
            'POST api/admin/integrations/sources/{source}/observations' => ['source'],
            'POST api/admin/integrations/sources/{source}/onboarding-versions' => ['source'],
            'POST api/admin/integrations/sources/{source}/payload-objects/{object}/hold-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/payload-objects/{object}/integrity-recovery-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/payload-objects/{object}/purge-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/payload-quarantines/{quarantine}/purge-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/payload-quarantines/{quarantine}/release-requests' => ['source'],
            'POST api/admin/integrations/sources/{source}/readiness-assessments' => ['source'],
            'POST api/admin/integrations/sources/{source}/slo-breaches/{breach}/acknowledge' => ['source'],
            'POST api/admin/integrations/sources/{source}/slo-breaches/{breach}/escalate' => ['source'],
            'POST api/admin/integrations/sources/{source}/slo-breaches/{breach}/incident-link' => ['source'],
            'POST api/admin/integrations/sources/{source}/slo-breaches/{breach}/review' => ['source'],
        ];

        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (\Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/admin/integrations/'))
            ->flatMap(function (\Illuminate\Routing\Route $route): array {
                $boundaries = collect($route->gatherMiddleware())
                    ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'admin.scope:')
                        || str_starts_with($middleware, RequireAdminScope::class.':'))
                    ->map(fn (string $middleware): string => str($middleware)->afterLast(':')->toString())
                    ->sort()
                    ->values()
                    ->all();

                return collect($route->methods())
                    ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
                    ->mapWithKeys(fn (string $method): array => ["{$method} {$route->uri()}" => $boundaries])
                    ->all();
            })
            ->sortKeys()
            ->all();

        $this->assertSame($expected, $actual);
    }

    private function selectFirstScope(User $user): void
    {
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->firstOrganization->organization_id,
            'facility_id' => $this->firstFacility->facility_id,
            'source_id' => $this->firstSourceId,
            'return_path' => '/integrations',
        ])->assertRedirect();
    }

    private function organization(string $key, string $name): Organization
    {
        return Organization::create([
            'organization_key' => $key,
            'name' => $name,
            'kind' => 'idn',
        ]);
    }

    private function facility(Organization $organization, string $key, string $name): Facility
    {
        return Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => $key,
            'facility_name' => $name,
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function source(
        Organization $organization,
        Facility $facility,
        string $key,
        array $overrides = [],
    ): int {
        $sourceId = (int) DB::table('integration.sources')->insertGetId(array_merge([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => $key,
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'source_name' => $key,
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'go_live_status' => 'testing',
            'lifecycle_state' => 'validating',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'source_id');
        app(SourceConfigurationVersionService::class)->initialize(
            $sourceId,
            null,
            'Initial administration scope test configuration.',
            (string) Str::uuid(),
        );
        app(SourceLifecycleService::class)->initialize(
            $sourceId,
            null,
            'Initial administration scope test lifecycle.',
        );

        return $sourceId;
    }

    private function configurationVersionId(int $sourceId): int
    {
        return (int) DB::table('integration.sources')->where('source_id', $sourceId)
            ->value('current_configuration_version_id');
    }
}
