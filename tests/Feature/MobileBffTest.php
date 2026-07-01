<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conformance tests for the Hummingbird mobile BFF (/api/mobile/v1/*): every read endpoint is
 * auth-gated (Sanctum + `mobile:read`) and returns the uniform envelope. The endpoints are
 * defensive (they return the envelope even against a sparse DB), so this stays fast without the
 * full demo seed while still exercising routing, middleware, and the controllers' shape.
 */
class MobileBffTest extends TestCase
{
    use RefreshDatabase;

    /** The GET reads the native apps depend on. */
    private const READ_ENDPOINTS = [
        '/api/mobile/v1/rtdc/census',
        '/api/mobile/v1/rtdc/house',
        '/api/mobile/v1/rtdc/bed-requests',
        '/api/mobile/v1/for-you',
        '/api/mobile/v1/transport/queue',
        '/api/mobile/v1/evs/queue',
        '/api/mobile/v1/command/house',
        '/api/mobile/v1/or/board',
        '/api/mobile/v1/staffing/overview',
        '/api/mobile/v1/improvement/pdsa',
        '/api/mobile/v1/improvement/opportunities',
        '/api/mobile/v1/ops/inbox',
    ];

    public function test_read_endpoints_require_authentication(): void
    {
        foreach (self::READ_ENDPOINTS as $path) {
            $this->getJson($path)->assertUnauthorized(); // 401 — no bearer token
        }
    }

    public function test_a_token_without_mobile_read_ability_is_rejected(): void
    {
        Sanctum::actingAs($this->user(), ['password:change']); // the scoped must-change token

        $this->getJson('/api/mobile/v1/rtdc/census')->assertForbidden(); // 403 — missing mobile:read
    }

    public function test_read_endpoints_return_the_uniform_envelope(): void
    {
        $this->seed(RtdcSeeder::class); // units + beds spine, so census/house/command have context
        Sanctum::actingAs($this->user(), ['mobile:read']);

        foreach (self::READ_ENDPOINTS as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta' => ['as_of', 'stale', 'version'],
                    'links',
                ]);
        }
    }

    public function test_mobile_act_is_required_for_writes(): void
    {
        Sanctum::actingAs($this->user(), ['mobile:read']); // read-only token

        // A write path (barrier resolve) must reject a read-only token before touching the resource.
        $this->postJson('/api/mobile/v1/rtdc/barriers/1/resolve')->assertForbidden();
    }

    private function user(): User
    {
        $user = new User;
        $user->name = 'BFF Test';
        $user->email = 'bfftest@example.com';
        $user->username = 'bfftest';
        $user->password = bcrypt('secret-test-password');
        $user->save();

        return $user;
    }
}
