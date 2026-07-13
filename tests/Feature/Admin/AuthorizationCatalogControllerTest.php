<?php

namespace Tests\Feature\Admin;

use App\Authorization\Capability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuthorizationCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_catalog_is_explicitly_gated_and_read_only(): void
    {
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $facilityAdmin = User::factory()->create(['role' => 'facility_admin', 'is_active' => true, 'must_change_password' => false]);
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($plain)->get('/admin/roles-capabilities')->assertForbidden();
        $this->actingAs($facilityAdmin)->get('/admin/roles-capabilities')->assertForbidden();

        $this->actingAs($auditor)->get('/admin/roles-capabilities')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/RolesCapabilities')
                ->where('counts.capabilities', count(Capability::cases()))
                ->where('counts.unclassifiedCapabilities', 0)
                ->where('currentPrincipal.globalScope', false)
                ->where('currentPrincipal.roles', ['auditor'])
                ->where('sourceOfTruth', 'config/authorization.php + App\\Authorization\\Capability')
                ->has('roles')
                ->has('capabilities'));

        $this->actingAs($auditor)->post('/admin/roles-capabilities')->assertMethodNotAllowed();
    }

    public function test_capability_metadata_is_exhaustive_and_nonduplicated(): void
    {
        $grouped = collect(config('authorization.capability_domains'))->flatten();
        $canonical = collect(Capability::cases())->map->value;

        $this->assertSame($canonical->sort()->values()->all(), $grouped->unique()->sort()->values()->all());
        $this->assertCount($canonical->count(), $grouped);
    }

    public function test_admin_profile_exposes_new_read_capabilities_but_only_super_admin_is_implicitly_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->get('/admin/roles-capabilities')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentPrincipal.globalScope', false)
                ->where('currentPrincipal.capabilities', fn (Collection $capabilities): bool => $capabilities->contains('viewAuthorization')
                    && $capabilities->contains('viewSystemHealth')
                    && $capabilities->contains('runDiagnostics')));
    }
}
