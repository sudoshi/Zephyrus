<?php

namespace Tests\Feature\Admin;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DataProtectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_protection_is_capability_gated_and_returns_only_bounded_authority_evidence(): void
    {
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($plain)->get('/admin/data-protection')->assertForbidden();

        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'raw_message', [
            'patient_name' => 'RECOGNIZABLE-ADMIN-PATIENT-5599',
            'token' => 'RECOGNIZABLE-ADMIN-TOKEN-5599',
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'must_change_password' => false]);

        $response = $this->actingAs($admin)->get('/admin/data-protection')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DataProtection')
                ->where('snapshot.scope.mode', 'capability_wide')
                ->where('snapshot.provider.status', 'ready')
                ->where('snapshot.provider.cipher', 'xchacha20-poly1305-ietf')
                ->where('snapshot.objects.total', 1)
                ->where('snapshot.objects.ready', 1)
                ->where('snapshot.objects.items', [])
                ->where('snapshot.quarantine.items', [])
                ->where('snapshot.governance.actionable', false)
                ->where('snapshot.governance.changes', [])
                ->where('snapshot.partitioning.status', 'blocked')
                ->has('snapshot.coverage.targets', 5));

        $this->assertStringNotContainsString('RECOGNIZABLE-ADMIN-PATIENT-5599', $response->getContent());
        $this->assertStringNotContainsString('RECOGNIZABLE-ADMIN-TOKEN-5599', $response->getContent());
        $this->assertStringNotContainsString('.zcp', $response->getContent());
        $this->assertStringNotContainsString('wrapped_data_key', $response->getContent());
        $this->assertStringNotContainsString('key_reference', $response->getContent());

        $source = DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
        $this->actingAs($admin)->put('/admin/active-scope', [
            'organization_id' => $source->organization_id,
            'facility_id' => $source->facility_id,
            'source_id' => $sourceId,
            'return_path' => '/admin/data-protection',
        ])->assertRedirect();
        $scoped = $this->actingAs($admin)->get('/admin/data-protection')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('snapshot.scope.mode', 'source')
                ->where('snapshot.scope.sourceId', $sourceId)
                ->where('snapshot.governance.actionable', true)
                ->has('snapshot.objects.items', 1)
                ->where('snapshot.objects.items.0.id', $stored->payloadObjectId)
                ->where('snapshot.objects.items.0.kind', 'raw_message')
                ->where('snapshot.objects.items.0.status', 'ready')
                ->where('snapshot.objects.items.0.deletionBlockers', [])
                ->missing('snapshot.objects.items.0.objectKey')
                ->missing('snapshot.objects.items.0.ciphertextSha256')
                ->missing('snapshot.objects.items.0.keyReference'));
        $this->assertStringNotContainsString('RECOGNIZABLE-ADMIN-PATIENT-5599', $scoped->getContent());
        $this->assertStringNotContainsString('RECOGNIZABLE-ADMIN-TOKEN-5599', $scoped->getContent());
    }

    private function source(): int
    {
        $organization = Organization::query()->create([
            'organization_key' => 'DATA_PROTECTION_ADMIN_ORG',
            'name' => 'Data Protection Admin Organization',
            'kind' => 'idn',
        ]);
        $facility = Facility::query()->create([
            'facility_key' => 'DATA_PROTECTION_ADMIN_FACILITY',
            'organization_id' => $organization->organization_id,
            'facility_name' => 'Data Protection Admin Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        return (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'data.protection.admin.source',
            'source_name' => 'Data Protection Admin Source',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'system_class' => 'ehr',
            'environment' => 'testing',
            'interface_type' => 'hl7v2',
            'active_status' => 'testing',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }
}
