<?php

namespace Tests\Feature\Security;

use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use Database\Seeders\E2eTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class E2eTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_actor_is_test_only_non_default_and_ready_for_login(): void
    {
        putenv('TEST_USERNAME=e2e_admin');
        putenv('TEST_PASSWORD=BrowserOnlyPassword!123');
        $_ENV['TEST_USERNAME'] = 'e2e_admin';
        $_ENV['TEST_PASSWORD'] = 'BrowserOnlyPassword!123';

        try {
            $this->seed(E2eTestSeeder::class);
        } finally {
            putenv('TEST_USERNAME');
            putenv('TEST_PASSWORD');
            unset($_ENV['TEST_USERNAME'], $_ENV['TEST_PASSWORD']);
        }

        $this->assertDatabaseHas('prod.users', [
            'username' => 'e2e_admin',
            'email' => 'e2e_admin@zephyrus.test',
            'role' => 'super_admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $actor = User::query()->where('username', 'e2e_admin')->firstOrFail();
        $this->assertTrue(Hash::check('BrowserOnlyPassword!123', $actor->password));
        $this->assertFalse(Hash::check('password', $actor->password));

        $controlPlane = $this->actingAs($actor)
            ->getJson('/api/admin/integrations/control-plane');
        $controlPlane
            ->assertOk()
            ->assertJsonPath('data.counts.sources', 16)
            ->assertJsonPath('data.counts.fhirConnections', 0);
        $controlPlaneDocument = json_decode($controlPlane->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $e2eSource = collect($controlPlaneDocument->data->sources)
            ->firstWhere('sourceKey', 'e2e.fhir.source');
        $this->assertNotNull($e2eSource);
        $this->assertInstanceOf(\stdClass::class, $e2eSource->queueState);
        $this->assertInstanceOf(\stdClass::class, $e2eSource->runtimeState);

        $this->assertDatabaseCount('prod.ancillary_orders', 66);
        $this->assertDatabaseCount('prod.ancillary_milestones', 328);
        $this->assertDatabaseHas('prod.or_cases', ['is_deleted' => false]);
        $this->assertDatabaseHas('prod.encounters', ['status' => 'active', 'is_deleted' => false]);

        $target = User::query()->where('username', 'e2e_lifecycle_target')->firstOrFail();
        $this->assertSame('Browser Lifecycle Target', $target->name);
        $this->assertSame('user', $target->role);
        $this->assertFalse($target->is_active);
        $this->assertTrue($target->must_change_password);
        $this->assertNotNull($target->deactivated_at);
        $this->assertFalse(Hash::check('password', $target->password));

        $identity = UserExternalIdentity::query()
            ->where('user_id', $target->id)
            ->where('provider', 'authentik')
            ->sole();
        $this->assertTrue($identity->is_active);
        $this->assertDatabaseHas('governance.identity_link_events', [
            'external_identity_id' => $identity->id,
            'subject_user_id' => $target->id,
            'event_type' => 'linked',
        ]);
    }
}
