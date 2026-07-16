<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AiProviderPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_providers_page_authorization_allows_stewards_and_denies_everyone_else(): void
    {
        $this->seedEddyPolicy();
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $facilityAdmin = User::factory()->create(['role' => 'facility_admin', 'is_active' => true, 'must_change_password' => false]);
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);
        $steward = User::factory()->create(['role' => 'ai_governance_steward', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($plain)->get('/admin/ai-providers')->assertForbidden();
        // Cockpit policy authority deliberately does not include AI governance.
        $this->actingAs($facilityAdmin)->get('/admin/ai-providers')->assertForbidden();

        $this->actingAs($auditor)->get('/admin/ai-providers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AiProviders')
                ->where('canManage', false)
                ->has('document.profiles', 1)
                ->where('document.profiles.0.profile_id', 'local-medgemma')
                ->has('document.surfaces', 1)
                ->has('catalog.surfaces')
                ->has('guardrails.cloudKillSwitchEnabled')
                ->has('pendingChanges'));

        $this->actingAs($steward)->get('/admin/ai-providers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true));

        // No provider secret material is present anywhere in the page payload.
        // (Entitlement NAMES like org_api_key are catalog vocabulary, not secrets.)
        $content = $this->actingAs($steward)->get('/admin/ai-providers')->getContent();
        $this->assertStringNotContainsString('&quot;api_key&quot;:', $content);
        $this->assertStringNotContainsString('client_secret', $content);
        $this->assertStringNotContainsString('provider_setting_type', $content);
    }

    public function test_governed_ai_policy_flow_enforces_author_approver_separation_over_http(): void
    {
        $this->seedEddyPolicy();
        $author = User::factory()->create(['role' => 'ai_governance_steward', 'is_active' => true, 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'ai_governance_steward', 'is_active' => true, 'must_change_password' => false]);
        $document = $this->documentWithChatDisabled();

        // A read-only principal cannot author policy changes.
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($auditor)->withSession($this->stepUp())
            ->postJson('/admin/ai-providers/changes', [
                'document' => $document,
                'change_reason' => 'Read-only principals must not author policy.',
            ])
            ->assertForbidden();

        $create = $this->actingAs($author)->withSession($this->stepUp())
            ->postJson('/admin/ai-providers/changes', [
                'document' => $document,
                'change_reason' => 'Disable chat routing while the model is re-evaluated.',
            ])
            ->assertCreated();
        $changeUuid = $create->json('changeRequestUuid');
        $this->assertSame('local_only', EddySurfacePolicy::query()->firstWhere('surface', 'chat')->provider_mode);

        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/ai-providers/changes/{$changeUuid}/decision", [
                'approve' => true,
                'reason' => 'Trying to approve my own AI policy change.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'author_approver_conflict');

        $this->actingAs($approver)->withSession($this->stepUp())
            ->postJson("/admin/ai-providers/changes/{$changeUuid}/decision", [
                'approve' => true,
                'reason' => 'Independent review of the disabled chat surface completed.',
            ])
            ->assertOk();

        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/ai-providers/changes/{$changeUuid}/apply", [])
            ->assertOk()
            ->assertJsonPath('policyKey', 'eddy')
            ->assertJsonPath('changeKind', 'governed_application');

        $this->assertSame('disabled', EddySurfacePolicy::query()->firstWhere('surface', 'chat')->provider_mode);
        $this->assertDatabaseHas('governance.ai_provider_policy_versions', [
            'policy_key' => 'eddy',
            'change_kind' => 'governed_application',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_dry_run_simulation_requires_capability_and_stores_no_prompt_or_patient_content(): void
    {
        $this->seedEddyPolicy();
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($plain)->postJson('/admin/ai-providers/simulate', ['surface' => 'chat'])->assertForbidden();

        // Prompt text and patient context are rejected outright, not sanitized.
        $this->actingAs($auditor)
            ->postJson('/admin/ai-providers/simulate', [
                'surface' => 'chat',
                'message' => 'Patient John Smith MRN 1234 has chest pain.',
            ])
            ->assertStatus(422);
        $this->assertDatabaseMissing('audit.user_events', ['action' => 'administration.ai_provider.route_simulated']);

        $response = $this->actingAs($auditor)
            ->postJson('/admin/ai-providers/simulate', ['surface' => 'chat'])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('surface', 'chat')
            ->assertJsonPath('selected_profile.profile_id', 'local-medgemma')
            ->assertJsonPath('will_call_paid_provider', false)
            ->assertJsonPath('phi_posture.never_send_phi_to_cloud', true);
        $this->assertArrayNotHasKey('message_length', $response->json());

        // The only persisted artifact is a non-content audit event whose
        // metadata is restricted to routing facts.
        $event = UserEvent::query()->where('action', 'administration.ai_provider.route_simulated')->sole();
        $this->assertSame('ai_provider_policy', $event->target_type);
        $this->assertSame('eddy', $event->target_id);
        $metadata = $event->metadata ?? [];
        $this->assertEqualsCanonicalizing(
            ['surface', 'provider_mode', 'selected_profile_id', 'fallback_used', 'will_call_paid_provider'],
            array_keys($metadata),
        );
        $this->assertSame('chat', $metadata['surface']);
        $this->assertSame('local-medgemma', $metadata['selected_profile_id']);

        // Nothing else was written: no Eddy conversation or message rows exist.
        $this->assertSame(0, (int) DB::table('eddy.eddy_conversations')->count());
    }

    /** @return array<string, mixed> */
    private function documentWithChatDisabled(): array
    {
        return [
            'profiles' => [[
                'profile_id' => 'local-medgemma',
                'display_name' => 'Local MedGemma',
                'provider_type' => 'ollama',
                'transport' => 'ollama_chat',
                'entitlement_type' => 'local',
                'model' => 'medgemma-27b',
                'base_url' => 'http://127.0.0.1:11434',
                'region' => null,
                'is_enabled' => true,
                'capabilities' => ['chat', 'streaming', 'tool_calling'],
                'safety' => ['patient_level_context_allowed' => true],
                'limits' => ['timeout' => 120, 'max_output_tokens' => null, 'monthly_budget_usd' => null],
                'fallback_profile_ids' => [],
            ]],
            'surfaces' => [[
                'surface' => 'chat',
                'provider_mode' => 'disabled',
                'default_profile_id' => 'local-medgemma',
                'fallback_profile_ids' => [],
                'allow_cloud' => false,
                'never_send_phi_to_cloud' => true,
                'required_capabilities' => [],
            ]],
        ];
    }

    private function seedEddyPolicy(): void
    {
        EddyProviderProfile::query()->create([
            'profile_id' => 'local-medgemma',
            'display_name' => 'Local MedGemma',
            'provider_type' => 'ollama',
            'transport' => 'ollama_chat',
            'entitlement_type' => 'local',
            'model' => 'medgemma-27b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_enabled' => true,
            'capabilities' => ['chat', 'streaming', 'tool_calling'],
            'safety' => ['patient_level_context_allowed' => true],
            'limits' => ['timeout' => 120],
            'fallback_profile_ids' => [],
        ]);
        EddySurfacePolicy::query()->create([
            'surface' => 'chat',
            'provider_mode' => 'local_only',
            'default_profile_id' => 'local-medgemma',
            'fallback_profile_ids' => [],
            'never_send_phi_to_cloud' => true,
            'allow_cloud' => false,
            'required_capabilities' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }
}
