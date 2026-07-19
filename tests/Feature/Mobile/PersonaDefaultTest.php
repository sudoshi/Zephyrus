<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Services\Mobile\MobilePersonaCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Default-persona resolution (4D navigator lens-collapse fix, 2026-07-19):
 * a broad-access operator with no explicit persona must land on the HOUSE
 * lens. Defaulting to allowedForUser()[0] gave admins charge_nurse — a
 * unit-depth lens whose scope auto-selects the first unit, silently
 * collapsing the navigator to one section of one floor.
 */
class PersonaDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function resolvedPersona(User $user, array $query = []): string
    {
        $request = Request::create('/api/patient-flow/summary', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return app(MobilePersonaCatalog::class)->fromRequest($request);
    }

    private function activeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    public function test_broad_access_user_defaults_to_the_house_lens(): void
    {
        $this->assertSame('house_supervisor', $this->resolvedPersona($this->activeUser('superuser')));
        $this->assertSame('house_supervisor', $this->resolvedPersona($this->activeUser('admin')));
    }

    public function test_broad_access_user_can_still_assume_an_explicit_persona(): void
    {
        $persona = $this->resolvedPersona($this->activeUser('superuser'), ['persona' => 'charge_nurse']);
        $this->assertSame('charge_nurse', $persona);
    }

    public function test_role_scoped_user_keeps_their_own_persona_default(): void
    {
        $this->assertSame('charge_nurse', $this->resolvedPersona($this->activeUser('charge_nurse')));
    }
}
