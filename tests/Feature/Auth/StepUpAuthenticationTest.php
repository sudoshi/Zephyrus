<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\Oidc\ValidatedClaims;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class StepUpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_confirmation_records_recent_step_up_and_audits_it(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->post('/confirm-password', [
            'password' => 'correct-password',
        ])->assertRedirect();

        $this->assertSame('password', session(StepUpAuthenticationService::METHOD));
        $this->assertIsInt(session(StepUpAuthenticationService::VERIFIED_AT));
        $this->assertTrue(app(StepUpAuthenticationService::class)->isSatisfied($this->requestFor($user)));
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->id,
            'action' => 'security.step_up.completed',
            'outcome' => 'success',
            'auth_method' => 'password',
        ]);
    }

    public function test_stale_or_untyped_session_evidence_is_not_accepted(): void
    {
        config()->set('security.step_up.ttl_seconds', 60);
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        session()->put([
            StepUpAuthenticationService::VERIFIED_AT => time() - 61,
            StepUpAuthenticationService::METHOD => 'password',
        ]);

        $request = $this->requestFor($user);
        $this->assertFalse(app(StepUpAuthenticationService::class)->isSatisfied($request));

        session([
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'untrusted',
        ]);
        $this->assertFalse(app(StepUpAuthenticationService::class)->isSatisfied($request));
    }

    public function test_oidc_step_up_requires_recent_auth_time_and_approved_mfa_evidence(): void
    {
        config()->set('security.step_up.oidc_mfa_amr', ['mfa', 'webauthn']);
        config()->set('security.step_up.oidc_mfa_acr', ['urn:example:loa:2']);
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        $service = app(StepUpAuthenticationService::class);
        $request = $this->requestFor($user);

        $weak = new ValidatedClaims('sub', $user->email, $user->name, ['Zephyrus Users'], time(), ['pwd']);
        $this->assertFalse($service->markOidcMfa($request, $weak));

        $stale = new ValidatedClaims('sub', $user->email, $user->name, ['Zephyrus Users'], time() - 301, ['mfa']);
        $this->assertFalse($service->markOidcMfa($request, $stale));

        $strong = new ValidatedClaims('sub', $user->email, $user->name, ['Zephyrus Users'], time(), ['pwd', 'mfa']);
        $this->assertTrue($service->markOidcMfa($request, $strong));
        $this->assertSame('oidc_mfa', session(StepUpAuthenticationService::METHOD));
        $this->assertTrue($service->isSatisfied($request));
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/step-up-test');
        $request->setUserResolver(fn (): User => $user);
        $request->setLaravelSession(session()->driver());

        return $request;
    }
}
