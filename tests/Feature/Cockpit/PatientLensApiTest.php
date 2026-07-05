<?php

namespace Tests\Feature\Cockpit;

use App\Models\BedRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-3 — the A2P patient lens as a web cockpit drill
 * (GET /api/cockpit/patient/{contextRef}). Proves the two RBAC layers: the
 * EnforceFlowLens:patients gate (persona may see patients at all) and the
 * service gate (this persona may see THIS patient). PHPUnit class syntax —
 * Pest is excluded on this environment.
 */
class PatientLensApiTest extends TestCase
{
    use RefreshDatabase;

    /** A broad-access user resolving to an assumed persona, mirroring MobileBffTest. */
    private function admin(): User
    {
        $user = new User;
        $user->name = 'Lens Test';
        $user->email = 'lenstest@example.com';
        $user->username = 'lenstest';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    private function seedPatientContext(): string
    {
        BedRequest::create([
            'patient_ref' => 'SECRET-MRN-LENS',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        return app(MobilePatientContextService::class)->contextRefFor('SECRET-MRN-LENS');
    }

    public function test_returns_the_a2p_context_for_an_authorized_persona(): void
    {
        $contextRef = $this->seedPatientContext();

        $response = $this->actingAs($this->admin())
            ->getJson("/api/cockpit/patient/{$contextRef}?persona=bed_manager");

        $response->assertOk()
            ->assertJsonPath('altitude', 'A2P')
            ->assertJsonPath('patient.patient_context_ref', $contextRef)
            ->assertJsonPath('phi_policy.requires_detail_auth', true);

        // The header spine is present (current/target location, responsible team).
        $this->assertIsArray($response->json('header'));
        $this->assertIsArray($response->json('status_spine'));
        $this->assertIsArray($response->json('timeline'));
    }

    public function test_a_patient_dots_none_persona_is_forbidden_by_the_flow_lens(): void
    {
        $contextRef = $this->seedPatientContext();

        // The executive lens is patient_dots=none — the middleware denies before
        // the service ever runs (the CMIO A2P matrix, enforced server-side).
        $this->actingAs($this->admin())
            ->getJson("/api/cockpit/patient/{$contextRef}?persona=executive")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'flow_lens_forbidden')
            ->assertJsonPath('error.unauthorized_state', true);
    }

    public function test_an_unresolvable_context_ref_is_forbidden_by_the_service(): void
    {
        // A well-formed but unknown ptok clears the route constraint and the
        // flow-lens gate, then the service refuses it (no such patient context).
        $this->actingAs($this->admin())
            ->getJson('/api/cockpit/patient/ptok_deadbeefdeadbeefdeadbeef?persona=bed_manager')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'patient_context_forbidden');
    }

    public function test_a_non_ptok_ref_never_reaches_the_service(): void
    {
        // The ptok_ route constraint 404s a raw MRN before controller/service.
        $this->actingAs($this->admin())
            ->getJson('/api/cockpit/patient/SECRET-MRN-LENS?persona=bed_manager')
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/patient/ptok_deadbeefdeadbeefdeadbeef')
            ->assertStatus(401);
    }
}
