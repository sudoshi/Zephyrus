<?php

namespace Tests\Unit\Auth\Oidc;

use App\Models\Auth\OidcEmailAlias;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OidcReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function claims(array $o = []): ValidatedClaims
    {
        return new ValidatedClaims(
            sub: $o['sub'] ?? 'sub-1',
            email: $o['email'] ?? 'new@example.com',
            name: $o['name'] ?? 'New User',
            groups: $o['groups'] ?? ['Zephyrus Users'],
        );
    }

    private function reconciler(): OidcReconciliationService
    {
        return new OidcReconciliationService(['Zephyrus Users'], ['Zephyrus Admins']);
    }

    public function test_links_an_existing_user_by_email_and_creates_an_identity_row(): void
    {
        $u = User::factory()->create(['email' => 'existing@example.com', 'is_active' => true]);

        $res = $this->reconciler()->reconcile($this->claims(['email' => 'Existing@Example.com']));

        $this->assertSame('linked_by_email', $res['reason']);
        $this->assertSame($u->id, $res['user']->id);
        $this->assertTrue(UserExternalIdentity::where('user_id', $u->id)->where('provider_subject', 'sub-1')->exists());
    }

    public function test_jit_creates_a_user_as_role_user_when_in_the_allowed_group(): void
    {
        $res = $this->reconciler()->reconcile($this->claims(['email' => 'jit@example.com', 'groups' => ['Zephyrus Users']]));

        $this->assertSame('created_jit', $res['reason']);
        $this->assertSame('user', $res['user']->role);
        $this->assertFalse($res['user']->must_change_password);
        $this->assertTrue($res['user']->is_active);
        $this->assertNotEmpty($res['user']->username);
    }

    public function test_jit_creates_a_user_as_role_admin_when_in_the_admin_group(): void
    {
        $res = $this->reconciler()->reconcile($this->claims(['email' => 'boss@example.com', 'groups' => ['Zephyrus Admins']]));
        $this->assertSame('admin', $res['user']->role);
    }

    public function test_denies_an_unknown_user_in_no_allowed_group(): void
    {
        $this->expectException(OidcAccessDeniedException::class);
        $this->reconciler()->reconcile($this->claims(['email' => 'stranger@example.com', 'groups' => ['Other']]));
    }

    public function test_resolves_an_email_alias_to_an_existing_privileged_account(): void
    {
        $admin = User::factory()->create(['email' => 'admin@acumenus.net', 'is_active' => true]);
        OidcEmailAlias::create(['alias_email' => 'me@personal.com', 'canonical_email' => 'admin@acumenus.net']);

        $res = $this->reconciler()->reconcile($this->claims(['email' => 'me@personal.com', 'groups' => []]));

        $this->assertSame('linked_by_alias', $res['reason']);
        $this->assertSame($admin->id, $res['user']->id);
    }
}
