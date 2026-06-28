<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use App\Models\User;
use App\Services\Eddy\EddyProviderPolicyService;
use Database\Seeders\EddySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EddyProviderPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private EddyProviderPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EddyProviderPolicyService;
    }

    public function test_resolves_local_profile_for_phi_free_surface(): void
    {
        $this->seed(EddySeeder::class);

        $payload = $this->service->payloadForSurface('chat');

        $this->assertNotNull($payload);
        $this->assertSame('local-medgemma', $payload['profile_id']);
        $this->assertSame('ollama', $payload['provider_type']);
        $this->assertSame('local_first', $payload['mode']);
        // Secrets never cross the boundary — no api_key in the payload.
        $this->assertArrayNotHasKey('api_key', $payload['settings']);
    }

    public function test_agent_surface_is_blocked_until_cloud_is_allowed(): void
    {
        $this->seed(EddySeeder::class);

        // eddy_agent ships cloud_first with allow_cloud=false → no valid profile.
        $this->assertNull($this->service->payloadForSurface('eddy_agent'));

        // Gate 1 only (allow_cloud) is NOT enough: the PHI gate still blocks a cloud
        // profile that isn't BAA-cleared on a never_send_phi_to_cloud surface.
        EddySurfacePolicy::where('surface', 'eddy_agent')->update(['allow_cloud' => true]);
        $this->assertNull($this->service->payloadForSurface('eddy_agent'));

        // Gate 2: admin attests BAA coverage on the profile (patient_level_context_allowed).
        // Both gates open → the frontier profile resolves. (Use save() so the jsonb cast applies.)
        $frontier = EddyProviderProfile::where('profile_id', 'claude-frontier')->firstOrFail();
        $frontier->safety = ['patient_level_context_allowed' => true];
        $frontier->save();

        $payload = $this->service->payloadForSurface('eddy_agent');
        $this->assertNotNull($payload);
        $this->assertSame('claude-frontier', $payload['profile_id']);
    }

    public function test_cloud_profile_blocked_when_surface_disallows_cloud(): void
    {
        $this->seed(EddySeeder::class);

        $profile = EddyProviderProfile::where('profile_id', 'claude-frontier')->firstOrFail();
        $policy = EddySurfacePolicy::where('surface', 'chat')->firstOrFail(); // allow_cloud=false

        $errors = $this->service->validateProfileForSurface($profile, $policy);

        $this->assertContains('cloud_not_allowed', $errors);
    }

    public function test_missing_capabilities_detected(): void
    {
        $profile = EddyProviderProfile::create([
            'profile_id' => 'weak-local',
            'display_name' => 'Weak local',
            'provider_type' => 'ollama',
            'transport' => 'ollama_chat',
            'entitlement_type' => 'local',
            'model' => 'tiny',
            'is_enabled' => true,
            'capabilities' => ['chat'], // missing streaming
        ]);
        $policy = EddySurfacePolicy::create([
            'surface' => 'chat',
            'provider_mode' => 'local_only',
            'default_profile_id' => 'weak-local',
            'never_send_phi_to_cloud' => true,
            'allow_cloud' => false,
            'required_capabilities' => ['chat', 'streaming'],
        ]);

        $errors = $this->service->validateProfileForSurface($profile, $policy);

        $this->assertContains('missing_capabilities:streaming', $errors);
    }

    public function test_patient_context_not_cloud_safe_blocks_cloud(): void
    {
        $profile = EddyProviderProfile::create([
            'profile_id' => 'cloud-no-baa',
            'display_name' => 'Cloud (no patient-level)',
            'provider_type' => 'anthropic',
            'transport' => 'anthropic_messages',
            'entitlement_type' => 'org_api_key',
            'model' => 'claude-sonnet-4-6',
            'is_enabled' => true,
            'capabilities' => ['chat', 'streaming'],
            'safety' => ['patient_level_context_allowed' => false],
        ]);
        $policy = EddySurfacePolicy::create([
            'surface' => 'rtdc',
            'provider_mode' => 'cloud_first',
            'default_profile_id' => 'cloud-no-baa',
            'never_send_phi_to_cloud' => true,
            'allow_cloud' => true,
            'required_capabilities' => ['chat', 'streaming'],
        ]);

        $errors = $this->service->validateProfileForSurface($profile, $policy);

        $this->assertContains('patient_context_not_cloud_safe', $errors);
    }

    public function test_disabled_surface_returns_disabled_stub(): void
    {
        EddySurfacePolicy::create([
            'surface' => 'chat',
            'provider_mode' => 'disabled',
            'default_profile_id' => 'local-medgemma',
        ]);

        $payload = $this->service->payloadForSurface('chat');

        $this->assertNotNull($payload);
        $this->assertSame('disabled', $payload['mode']);
    }

    public function test_simulate_route_reports_no_paid_provider_for_local(): void
    {
        $this->seed(EddySeeder::class);

        $sim = $this->service->simulateRoute(['surface' => 'chat', 'message' => 'hi']);

        $this->assertTrue($sim['configured']);
        $this->assertFalse($sim['will_call_paid_provider']);
        $this->assertSame('zero_api_cost', $sim['estimated_budget_impact']);
    }

    public function test_user_can_mint_scoped_eddy_token_but_never_approve(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('eddy-agent', ['ops:read', 'ops:draft'], now()->addMinutes(10));

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertSame(['ops:read', 'ops:draft'], $token->accessToken->abilities);
        $this->assertTrue($token->accessToken->can('ops:read'));
        $this->assertTrue($token->accessToken->can('ops:draft'));
        // ops:approve is NEVER minted to Eddy — humans approve.
        $this->assertFalse($token->accessToken->can('ops:approve'));
    }
}
