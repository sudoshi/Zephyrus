<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionWebBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_phpunit_process_uses_a_disposable_database(): void
    {
        $database = (string) DB::scalar('select current_database()');

        $this->assertMatchesRegularExpression('/^zephyrus_test_[a-f0-9]{12}$/', $database);
        $this->assertNotSame('zephyrus_test', $database);
    }

    public function test_protected_web_routes_require_a_real_session_by_default(): void
    {
        config()->set('demo.auto_login_enabled', false);
        $usersBefore = User::query()->count();

        foreach (['/dashboard', '/admin', '/users', '/admin/enterprise-setup', '/integrations'] as $path) {
            $this->get($path)->assertRedirect('/login');
            $this->assertGuest();
        }

        $this->assertSame($usersBefore, User::query()->count());
        $this->getJson('/api/admin/integrations/health')->assertUnauthorized();
    }

    public function test_production_refuses_demo_auto_login_even_when_misconfigured_on(): void
    {
        $admin = User::factory()->create([
            'username' => 'demo-admin',
            'role' => 'admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        config()->set('demo.auto_login_enabled', true);
        config()->set('demo.auto_login_username', $admin->username);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->get('/admin')->assertRedirect('/login');
            $this->assertGuest();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_explicit_nonproduction_demo_login_uses_an_existing_eligible_account_without_mutating_it(): void
    {
        $admin = User::factory()->create([
            'username' => 'sealed-demo-admin',
            'role' => 'admin',
            'workflow_preference' => 'rtdc',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $before = $admin->only(['role', 'workflow_preference', 'is_active', 'must_change_password']);

        config()->set('demo.auto_login_enabled', true);
        config()->set('demo.auto_login_username', $admin->username);

        $this->get('/admin')->assertOk();

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('rtdc', session('workflow'));
        $this->assertSame($before, $admin->fresh()->only(array_keys($before)));
    }

    public function test_demo_login_never_creates_or_repairs_an_unavailable_account(): void
    {
        config()->set('demo.auto_login_enabled', true);
        config()->set('demo.auto_login_username', 'missing-demo-account');
        $usersBefore = User::query()->count();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame($usersBefore, User::query()->count());

        $ineligible = User::factory()->create([
            'username' => 'ineligible-demo-account',
            'role' => 'user',
            'is_active' => false,
            'must_change_password' => true,
        ]);
        config()->set('demo.auto_login_username', $ineligible->username);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertFalse($ineligible->fresh()->is_active);
        $this->assertTrue($ineligible->fresh()->must_change_password);
        $this->assertSame('user', $ineligible->fresh()->role);
    }

    public function test_application_owns_a_nonce_based_restrictive_csp(): void
    {
        $response = $this->get('/login')->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('default-src *', $policy);
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('same-site', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));

        preg_match("/'nonce-([^']+)'/", $policy, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $response->assertSee('nonce="'.($matches[1] ?? '').'"', false);
    }

    public function test_cors_allows_only_an_explicit_browser_origin(): void
    {
        config()->set('cors.allowed_origins', ['https://trusted-operations.example']);

        $this->withHeader('Origin', 'https://trusted-operations.example')
            ->getJson('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', 'https://trusted-operations.example');

        $this->flushHeaders();
        $untrusted = $this->call(
            'GET',
            '/api/health',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'https://untrusted.example',
            ],
        );
        $this->assertNotSame(
            'https://untrusted.example',
            $untrusted->headers->get('Access-Control-Allow-Origin'),
        );
        $this->assertNotSame('*', $untrusted->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_apache_policy_no_longer_overrides_cors_csrf_or_csp(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertIsString($htaccess);
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $htaccess);
        $this->assertStringNotContainsString('Content-Security-Policy', $htaccess);
        $this->assertStringNotContainsString('Header unset X-CSRF', $htaccess);
        $this->assertStringNotContainsString('REQUEST_METHOD} OPTIONS', $htaccess);
        $this->assertFileDoesNotExist(public_path('direct-login.php'));
    }
}
