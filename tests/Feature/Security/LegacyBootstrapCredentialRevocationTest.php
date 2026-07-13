<?php

namespace Tests\Feature\Security;

use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LegacyBootstrapCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const KNOWN_PASSWORDS = [
        'admin' => 'password',
        'sanjay' => 'sanjay',
        'kartheek' => 'kartheek',
        'darshan' => 'darshan',
        'devsheth' => 'devsheth',
    ];

    public function test_migrations_deactivate_and_randomize_every_known_bootstrap_credential(): void
    {
        foreach (self::KNOWN_PASSWORDS as $username => $password) {
            $user = User::query()->where('username', $username)->firstOrFail();
            $this->assertFalse($user->is_active, $username.' must be inactive');
            $this->assertTrue($user->must_change_password, $username.' must require credential replacement');
            $this->assertSame('user', $user->role, $username.' must retain no scalar privilege');
            $this->assertFalse(Hash::check($password, $user->password), $username.' still has a known password');
            $this->assertSame([], $user->getRoleNames()->all(), $username.' must retain no Spatie role');
        }
    }

    public function test_revocation_migration_unlinks_external_access_with_hash_only_evidence(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'is_active' => true,
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ])->save();
        $identity = UserExternalIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'authentik',
            'provider_subject' => 'legacy-sensitive-subject',
            'provider_email_at_link' => 'admin@example.com',
            'linked_at' => now()->subYear(),
            'is_active' => true,
        ]);

        $migration = require database_path('migrations/2026_07_13_000200_revoke_legacy_bootstrap_credentials.php');
        $migration->up();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertFalse($identity->fresh()->is_active);
        $event = DB::table('governance.identity_link_events')
            ->where('external_identity_id', $identity->id)
            ->where('event_type', 'unlinked')
            ->sole();
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', 'authentik:legacy-sensitive-subject'), $event->provider_subject_sha256);
        $this->assertSame(hash('sha256', 'admin@example.com'), $event->provider_email_sha256);
        $this->assertStringNotContainsString('legacy-sensitive-subject', $encoded);
        $this->assertStringNotContainsString('admin@example.com', $encoded);
    }

    public function test_user_seeder_creates_nothing_without_explicit_demo_credentials(): void
    {
        User::query()->delete();
        config()->set('demo.enabled', true);
        $this->clearBootstrapEnvironment();

        $this->seed(UserSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_user_seeder_requires_demo_mode_and_a_strong_explicit_password(): void
    {
        User::query()->delete();
        $this->setBootstrapEnvironment('demo-operator', 'demo-operator@zephyrus.test', 'ExplicitDemoPassword!123');
        config()->set('demo.enabled', false);

        try {
            $this->seed(UserSeeder::class);
            $this->fail('Demo identity provisioning must require explicit demo mode.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('DEMO_MODE=true', $exception->getMessage());
        }

        config()->set('demo.enabled', true);
        putenv('DEMO_BOOTSTRAP_PASSWORD=short');
        $_ENV['DEMO_BOOTSTRAP_PASSWORD'] = 'short';
        try {
            $this->seed(UserSeeder::class);
            $this->fail('A short shared bootstrap password must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('16+ character', $exception->getMessage());
        } finally {
            $this->clearBootstrapEnvironment();
        }
    }

    public function test_explicit_nonproduction_bootstrap_identity_is_unprivileged_and_forces_password_change(): void
    {
        User::query()->delete();
        config()->set('demo.enabled', true);
        $this->setBootstrapEnvironment('demo-operator', 'demo-operator@zephyrus.test', 'ExplicitDemoPassword!123');

        try {
            $this->seed(UserSeeder::class);
        } finally {
            $this->clearBootstrapEnvironment();
        }

        $user = User::query()->where('username', 'demo-operator')->sole();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertSame('user', $user->role);
        $this->assertTrue(Hash::check('ExplicitDemoPassword!123', $user->password));
        $this->assertFalse($user->isAdministrator());
    }

    private function setBootstrapEnvironment(string $username, string $email, string $password): void
    {
        foreach (compact('username', 'email', 'password') as $key => $value) {
            $name = 'DEMO_BOOTSTRAP_'.strtoupper($key);
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function clearBootstrapEnvironment(): void
    {
        foreach (['USERNAME', 'EMAIL', 'PASSWORD'] as $suffix) {
            $name = 'DEMO_BOOTSTRAP_'.$suffix;
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
