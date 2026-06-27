<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — the For You queue BFF (/api/mobile/v1/for-you): token-gated, tier-ranked,
 * and PHI-minimized (no patient identifiers in the payload).
 */
class ForYouTest extends TestCase
{
    use RefreshDatabase;

    private function accessToken(): string
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
            'workflow_preference' => 'rtdc',
        ]);

        return $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');
    }

    public function test_for_you_requires_a_token(): void
    {
        $this->getJson('/api/mobile/v1/for-you')->assertStatus(401);
    }

    public function test_for_you_ranks_critical_first_and_counts_items(): void
    {
        // A high-acuity pending placement (critical) and an open barrier (warning).
        BedRequest::create(['patient_ref' => 'MRN-1', 'source' => 'ed', 'acuity_tier' => 1]);
        Barrier::create(['category' => 'placement']);

        $this->withToken($this->accessToken())->getJson('/api/mobile/v1/for-you')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.type', 'bed_request')
            ->assertJsonPath('data.0.tier', 'critical')
            ->assertJsonPath('data.1.type', 'barrier');
    }

    public function test_for_you_omits_patient_identifiers(): void
    {
        BedRequest::create(['patient_ref' => 'SECRET-MRN-999', 'source' => 'transfer', 'acuity_tier' => 3]);

        $body = $this->withToken($this->accessToken())->getJson('/api/mobile/v1/for-you')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('SECRET-MRN-999', $body);
    }
}
