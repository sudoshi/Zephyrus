<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\IntegrationConnectorTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_explicit_superuser_roles_can_open_integrations(): void
    {
        $this->get('/integrations')->assertForbidden();

        foreach (['user', 'admin', 'ops-leader', 'executive'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/integrations')->assertForbidden();
        }

        foreach (['superuser', 'super-admin', 'super_admin'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)
                ->get('/integrations')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Integrations/Index')
                    ->where('auth.can.view_integrations', true)
                    ->where('auth.can.manage_integrations', true));
        }
    }

    public function test_integration_apis_use_the_same_strict_authorization_boundary(): void
    {
        foreach (['user', 'admin', 'ops-leader'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->getJson('/api/admin/integrations/control-plane')->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/integrations/health')->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/integrations/enterprise')->assertForbidden();
            $this->actingAs($user)
                ->postJson('/api/admin/integrations/enterprise/writeback-drafts', [])
                ->assertForbidden();
        }

        $superuser = $this->user('superuser');
        $this->actingAs($superuser)->getJson('/api/admin/integrations/control-plane')->assertOk();
        $this->actingAs($superuser)->getJson('/api/admin/integrations/health')->assertOk();
        $this->actingAs($superuser)->getJson('/api/admin/integrations/enterprise')->assertOk();
    }

    public function test_transport_legacy_url_redirects_only_after_integration_authorization(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/transport/settings/integrations')->assertForbidden();

        $superuser = $this->user('superuser');
        $this->actingAs($superuser)
            ->get('/transport/settings/integrations')
            ->assertRedirect('/integrations');
    }

    public function test_control_plane_reports_observed_staleness_and_masks_sensitive_references(): void
    {
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'epic.adt.production',
            'source_name' => 'Epic ADT Production',
            'vendor' => 'Epic',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'hl7v2',
            'active_status' => 'active',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'live',
        ]);

        DB::table('integration.connector_watermarks')->insert([
            'source_id' => $source->source_id,
            'connector_key' => 'epic.adt',
            'scope_type' => 'feed',
            'scope_key' => 'patient-12345',
            'watermark_kind' => 'hl7_sequence',
            'watermark_value' => 'sensitive-cursor-value',
            'last_success_at' => now()->subHours(2),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('integration.source_credentials')->insert([
            'source_id' => $source->source_id,
            'credential_key' => 'mllp-client',
            'credential_type' => 'mtls',
            'secret_ref' => 'vault://secret/epic-password',
            'certificate_ref' => 'vault://certificate/epic-client',
            'jwks_uri' => null,
            'rotates_at' => now()->addDays(30),
            'is_active' => true,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user('superuser'))
            ->getJson('/api/admin/integrations/control-plane')
            ->assertOk()
            ->assertJsonPath('data.status', 'stale')
            ->assertJsonPath('data.sources.0.healthStatus', 'stale')
            ->assertJsonPath('data.watermarks.0.cursorStored', true)
            ->assertJsonPath('data.credentials.0.secretReferenceConfigured', true)
            ->assertJsonPath('data.credentials.0.certificateReferenceConfigured', true);

        $json = $response->getContent();
        $this->assertStringNotContainsString('sensitive-cursor-value', $json);
        $this->assertStringNotContainsString('patient-12345', $json);
        $this->assertStringNotContainsString('vault://secret/epic-password', $json);
        $this->assertStringNotContainsString('vault://certificate/epic-client', $json);
    }

    public function test_control_plane_reads_do_not_seed_or_promote_templates(): void
    {
        $superuser = $this->user('superuser');
        $before = [
            'playbooks' => DB::table('integration.connector_playbooks')->count(),
            'adapters' => DB::table('integration.coexistence_adapters')->count(),
            'engines' => DB::table('integration.interface_engines')->count(),
        ];

        $this->actingAs($superuser)->getJson('/api/admin/integrations/control-plane')->assertOk();
        $this->actingAs($superuser)->getJson('/api/admin/integrations/enterprise')->assertOk();

        $this->assertSame($before['playbooks'], DB::table('integration.connector_playbooks')->count());
        $this->assertSame($before['adapters'], DB::table('integration.coexistence_adapters')->count());
        $this->assertSame($before['engines'], DB::table('integration.interface_engines')->count());
    }

    public function test_explicit_connector_template_seeder_is_idempotent_and_truthful(): void
    {
        $this->seed(IntegrationConnectorTemplateSeeder::class);
        $epicUuid = DB::table('integration.connector_playbooks')->where('vendor_key', 'epic')->value('playbook_uuid');
        $this->seed(IntegrationConnectorTemplateSeeder::class);

        $this->assertSame(16, DB::table('integration.connector_playbooks')->count());
        $this->assertSame(3, DB::table('integration.coexistence_adapters')->count());
        $this->assertSame(1, DB::table('integration.interface_engines')->count());
        $this->assertSame(0, DB::table('integration.connector_playbooks')->whereNot('status', 'template')->count());
        $this->assertSame(0, DB::table('integration.coexistence_adapters')->whereNot('status', 'template')->count());
        $this->assertSame('template', DB::table('integration.interface_engines')->value('status'));
        $this->assertSame($epicUuid, DB::table('integration.connector_playbooks')->where('vendor_key', 'epic')->value('playbook_uuid'));
    }

    public function test_empty_connector_capabilities_serialize_as_json_objects(): void
    {
        $this->seed(IntegrationConnectorTemplateSeeder::class);
        DB::table('integration.connector_playbooks')->where('vendor_key', 'epic')->update([
            'capability_payload' => json_encode([]),
        ]);
        $user = $this->user('superuser');

        $controlPlane = $this->actingAs($user)->getJson('/api/admin/integrations/control-plane')->assertOk()->getContent();
        $enterprise = $this->actingAs($user)->getJson('/api/admin/integrations/enterprise')->assertOk()->getContent();

        $this->assertStringContainsString('"capabilities":{}', $controlPlane);
        $this->assertStringContainsString('"capabilities":{}', $enterprise);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
        ]);
    }
}
