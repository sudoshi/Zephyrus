# Authentik OIDC Login — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an additive, hardened Authentik OIDC login path ("Sign in with Authentik") to Zephyrus, with JIT group-gated provisioning, shipping disabled until configured.

**Architecture:** Port Aurora's `App\Services\Auth\Oidc\*` stack (discovery/JWKS, PKCE handshake, JWT validation, reconciliation, driver registry) adapted to Zephyrus's `web` guard, `prod` schema, and varchar `role`. The OIDC callback logs the user in via the web guard and mirrors the session setup of the existing `AuthenticatedSessionController::store`. All existing local/temp-password auth is untouched (additions only — `.claude/rules/auth-system.md`).

**Tech Stack:** Laravel 11 + Inertia/React (JSX), PostgreSQL `prod` schema, `firebase/php-jwt`, PHPUnit 11, Vitest. Authentik at `auth.acumenus.net` (Acropolis).

**Spec:** `docs/superpowers/specs/2026-06-21-authentik-oidc-login-design.md`

> **TESTING FRAMEWORK NOTE (2026-06-21):** The repo references Pest (`tests/Pest.php`, allow-plugins) but Pest is **not installed and cannot be** — every `laravel/framework` version new enough for Pest 3's `nunomaduro/collision` requirement (≥11.44.2) is blocked by composer security advisories, and the app intentionally pins to the last clean release (v11.41.0). Installing Pest would require a known-vulnerable framework or disabling composer's security auditing — unacceptable for a clinical app. **All backend tests in this plan are therefore written in PHPUnit 11 class syntax** (matching the 28 existing working test files), NOT the Pest snippets shown below. Conversion rules applied to every test task:
> - Class `final class XTest extends \Tests\TestCase` (boots the Laravel app so facades/config/DB work). Add `use \Illuminate\Foundation\Testing\RefreshDatabase;` for DB-touching tests.
> - `it('does x', fn)` → `public function test_does_x(): void`.
> - `beforeEach(fn)` → `protected function setUp(): void { parent::setUp(); ... }`.
> - `expect($a)->toBe($b)` → `$this->assertSame($b, $a)`; `->toBeTrue()`/`->toBeFalse()`/`->toBeNull()` → `assertTrue/assertFalse/assertNull`; `->toContain($x)` → `assertContains($x, ...)`; `->not->toBeEmpty()` → `assertNotEmpty`.
> - `(fn)->throws(X::class)` → `$this->expectException(X::class);` before the call.
> - Helper functions (e.g. `claims()`, `mintIdToken()`) become `private` methods or `static` helpers on the test class.
> - `Http::fake`, `Cache::flush`, `config()->set`, `$this->get`, `$this->actingAs`, factories — all work unchanged in PHPUnit + Laravel `TestCase`.
> The Vitest test (Task 13) is unaffected. Each implementer is given the already-converted PHPUnit code in its dispatch prompt.

---

## Conventions & deviations from the Aurora source

- **Schema:** every `app.` table reference becomes `prod.` (models `$table`, migrations, FKs `->on('prod.users')`).
- **No Sanctum token flow.** Aurora returns a Sanctum token and bounces through an SPA `/auth/callback?code=`. Zephyrus is Inertia/server-rendered: the callback calls `Auth::guard('web')->login($user)` and mirrors `AuthenticatedSessionController::store` (sets session `username`, `user_id`, default `workflow_preference`), then `redirect()->intended(route('dashboard'))`. The `exchange()`/`providers()` Aurora methods are **not** ported.
- **Roles:** no Spatie role assignment in reconciliation. Set the varchar `role` column: `admin` if the user is in an admin group, else `user`. `must_change_password=false`, `is_active=true`.
- **Local driver:** the registry holds only `authentik-oidc`. Local login stays on the existing protected controller (additions-only rule); we do NOT reroute it through the registry.
- **Admin UI:** managed via a seeder + a minimal superuser-gated JSON controller (`AuthProviderController`). A full React admin page is out of scope (YAGNI).
- **Enable model:** `config('services.oidc.enabled')` (env `OIDC_ENABLED`) OR the DB row's `is_enabled`. `client_secret` only ever from env.

## File Structure

**Create (backend):**
- `app/Contracts/AuthDriverInterface.php`
- `app/Auth/AuthDriverRegistry.php`
- `app/Auth/Drivers/AuthDriverResult.php`
- `app/Auth/Drivers/AuthDriverException.php`
- `app/Auth/Drivers/AuthentikOidcAuthDriver.php`
- `app/Services/Auth/Oidc/{OidcProviderConfig,OidcDiscoveryService,OidcHandshakeStore,OidcTokenValidator,OidcReconciliationService,ValidatedClaims}.php`
- `app/Services/Auth/Oidc/Exceptions/{OidcException,OidcTokenInvalidException,OidcAccessDeniedException}.php`
- `app/Models/Auth/{AuthProviderSetting,UserExternalIdentity,OidcEmailAlias}.php`
- `app/Http/Controllers/Auth/OidcController.php`
- `app/Http/Controllers/Admin/AuthProviderController.php`
- `config/auth-drivers.php`
- `database/migrations/2026_06_21_000001_create_auth_provider_settings_table.php`
- `database/migrations/2026_06_21_000002_create_user_external_identities_table.php`
- `database/migrations/2026_06_21_000003_create_oidc_email_aliases_table.php`
- `database/seeders/AuthProviderSettingSeeder.php`
- `scripts/authentik/provision_zephyrus_oidc.py`

**Modify (backend):**
- `config/services.php` (add `oidc` block)
- `app/Providers/AppServiceProvider.php` (bindings + registry)
- `routes/auth.php` (2 OIDC routes; admin route)
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (`create()`: add 2 Inertia props — additive)
- `.env.example` (OIDC_* keys)
- `composer.json` (via `composer require`)

**Modify (frontend):**
- `resources/js/Pages/Auth/Login.jsx` (SSO button, additive)

**Tests:**
- `tests/Unit/Auth/Oidc/{OidcProviderConfigTest,OidcDiscoveryServiceTest,OidcHandshakeStoreTest,OidcTokenValidatorTest,OidcReconciliationServiceTest}.php`
- `tests/Feature/Auth/OidcControllerTest.php`
- `tests/Feature/Admin/AuthProviderControllerTest.php`
- `resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx`

---

### Task 1: Add dependency + config scaffolding

**Files:**
- Modify: `composer.json` (via command)
- Create: `config/auth-drivers.php`
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Install firebase/php-jwt**

Run: `composer require firebase/php-jwt:^6.10`
Expected: package added; `composer.lock` updated.

- [ ] **Step 2: Create `config/auth-drivers.php`**

```php
<?php

use App\Auth\Drivers\AuthentikOidcAuthDriver;

return [
    'local' => [
        'enabled' => filter_var(env('LOCAL_AUTH_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'drivers' => [
        'authentik-oidc' => AuthentikOidcAuthDriver::class,
    ],
];
```

- [ ] **Step 3: Add the `oidc` block to `config/services.php`** (insert before the closing `];`)

```php
    'oidc' => [
        'enabled' => filter_var(env('OIDC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'discovery_url' => env('OIDC_DISCOVERY_URL', 'https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration'),
        'client_id' => env('OIDC_CLIENT_ID', ''),
        'client_secret' => env('OIDC_CLIENT_SECRET', ''),
        'redirect_uri' => env('OIDC_REDIRECT_URI', 'https://zephyrus.acumenus.net/auth/oidc/callback'),
        'scopes' => ['openid', 'profile', 'email', 'groups'],
        'allowed_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_ALLOWED_GROUPS', 'Zephyrus Users'))
        ))),
        'admin_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_ADMIN_GROUPS', 'Zephyrus Admins'))
        ))),
    ],
```

- [ ] **Step 4: Append OIDC keys to `.env.example`** (quote values with spaces — phpdotenv whitespace bug)

```dotenv

# Authentik OIDC (ships disabled; enable after the zephyrus-oidc app + secrets exist)
OIDC_ENABLED=false
OIDC_DISCOVERY_URL=https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration
OIDC_CLIENT_ID=
OIDC_CLIENT_SECRET=
OIDC_REDIRECT_URI=https://zephyrus.acumenus.net/auth/oidc/callback
OIDC_ALLOWED_GROUPS="Zephyrus Users"
OIDC_ADMIN_GROUPS="Zephyrus Admins"
```

- [ ] **Step 5: Verify config loads**

Run: `php artisan config:clear && php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_dump(config('services.oidc.enabled'), config('services.oidc.scopes'));"`
Expected: `bool(false)` and the scopes array. (Or simply `php artisan tinker --execute="dump(config('services.oidc'));"`.)

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/auth-drivers.php config/services.php .env.example
git commit -m "feat(auth): scaffold OIDC config + firebase/php-jwt dependency"
```

---

### Task 2: Migrations (prod schema)

**Files:**
- Create: `database/migrations/2026_06_21_000001_create_auth_provider_settings_table.php`
- Create: `database/migrations/2026_06_21_000002_create_user_external_identities_table.php`
- Create: `database/migrations/2026_06_21_000003_create_oidc_email_aliases_table.php`

- [ ] **Step 1: `..._000001_create_auth_provider_settings_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prod.auth_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type')->unique();
            $table->string('display_name');
            $table->boolean('is_enabled')->default(false);
            $table->integer('priority')->default(0);
            $table->text('settings')->nullable(); // encrypted:array → base64 text (NOT jsonb)
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('prod.users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prod.auth_provider_settings');
    }
};
```

- [ ] **Step 2: `..._000002_create_user_external_identities_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prod.user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_subject', 255);
            $table->string('provider_email_at_link', 255)->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('prod.users')->cascadeOnDelete();
            $table->unique(['provider', 'provider_subject']);
            $table->index(['provider', 'provider_email_at_link']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prod.user_external_identities');
    }
};
```

- [ ] **Step 3: `..._000003_create_oidc_email_aliases_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prod.oidc_email_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_email', 255)->unique();
            $table->string('canonical_email', 255);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('canonical_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prod.oidc_email_aliases');
    }
};
```

- [ ] **Step 4: Run the migrations (scoped path; never `migrate --force` on the full set)**

Run: `php artisan migrate --path=database/migrations/2026_06_21_000001_create_auth_provider_settings_table.php --path=database/migrations/2026_06_21_000002_create_user_external_identities_table.php --path=database/migrations/2026_06_21_000003_create_oidc_email_aliases_table.php`
Expected: three `DONE` lines.

- [ ] **Step 5: Verify tables exist**

Run: `php artisan tinker --execute="dump(Schema::hasTable('prod.auth_provider_settings'), Schema::hasTable('prod.user_external_identities'), Schema::hasTable('prod.oidc_email_aliases'));"`
Expected: three `true`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_21_00000*
git commit -m "feat(auth): migrations for OIDC settings, external identities, email aliases"
```

---

### Task 3: Models

**Files:**
- Create: `app/Models/Auth/AuthProviderSetting.php`
- Create: `app/Models/Auth/UserExternalIdentity.php`
- Create: `app/Models/Auth/OidcEmailAlias.php`

- [ ] **Step 1: `AuthProviderSetting.php`**

```php
<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthProviderSetting extends Model
{
    protected $table = 'prod.auth_provider_settings';

    protected $fillable = [
        'provider_type', 'display_name', 'is_enabled', 'priority', 'settings', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'settings' => 'encrypted:array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

- [ ] **Step 2: `UserExternalIdentity.php`**

```php
<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserExternalIdentity extends Model
{
    protected $table = 'prod.user_external_identities';

    protected $fillable = [
        'user_id', 'provider', 'provider_subject', 'provider_email_at_link', 'linked_at',
    ];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: `OidcEmailAlias.php`**

```php
<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class OidcEmailAlias extends Model
{
    protected $table = 'prod.oidc_email_aliases';

    protected $fillable = ['alias_email', 'canonical_email', 'note'];

    public static function canonicalFor(string $email): ?string
    {
        return static::query()
            ->whereRaw('lower(alias_email) = ?', [strtolower($email)])
            ->first()?->canonical_email;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/Auth
git commit -m "feat(auth): Eloquent models for OIDC settings, identities, aliases"
```

---

### Task 4: Exceptions + ValidatedClaims DTO

**Files:**
- Create: `app/Services/Auth/Oidc/Exceptions/OidcException.php`
- Create: `app/Services/Auth/Oidc/Exceptions/OidcTokenInvalidException.php`
- Create: `app/Services/Auth/Oidc/Exceptions/OidcAccessDeniedException.php`
- Create: `app/Services/Auth/Oidc/ValidatedClaims.php`

- [ ] **Step 1: `Exceptions/OidcException.php`**

```php
<?php

namespace App\Services\Auth\Oidc\Exceptions;

use RuntimeException;
use Throwable;

class OidcException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
```

- [ ] **Step 2: `Exceptions/OidcTokenInvalidException.php`**

```php
<?php

namespace App\Services\Auth\Oidc\Exceptions;

class OidcTokenInvalidException extends OidcException {}
```

- [ ] **Step 3: `Exceptions/OidcAccessDeniedException.php`**

```php
<?php

namespace App\Services\Auth\Oidc\Exceptions;

class OidcAccessDeniedException extends OidcException {}
```

- [ ] **Step 4: `ValidatedClaims.php`**

```php
<?php

namespace App\Services\Auth\Oidc;

final readonly class ValidatedClaims
{
    /** @param list<string> $groups */
    public function __construct(
        public string $sub,
        public string $email,
        public string $name,
        public array $groups,
    ) {}
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/Exceptions app/Services/Auth/Oidc/ValidatedClaims.php
git commit -m "feat(auth): OIDC exceptions + ValidatedClaims DTO"
```

---

### Task 5: OidcProviderConfig (TDD)

**Files:**
- Create: `app/Services/Auth/Oidc/OidcProviderConfig.php`
- Test: `tests/Unit/Auth/Oidc/OidcProviderConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Auth\AuthProviderSetting;
use App\Services\Auth\Oidc\OidcProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to env when a stored setting is an empty string', function () {
    config()->set('services.oidc.client_id', 'env-client');
    config()->set('services.oidc.enabled', true);
    config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
    config()->set('services.oidc.redirect_uri', 'https://app/auth/oidc/callback');

    AuthProviderSetting::create([
        'provider_type' => 'oidc',
        'display_name' => 'Sign in with Authentik',
        'is_enabled' => true,
        'settings' => ['client_id' => ''], // empty string must NOT override env
    ]);

    $config = app(OidcProviderConfig::class);

    expect($config->clientId())->toBe('env-client')
        ->and($config->isPubliclyAvailable())->toBeTrue();
});

it('is not publicly available when disabled', function () {
    config()->set('services.oidc.enabled', false);
    expect(app(OidcProviderConfig::class)->isPubliclyAvailable())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcProviderConfigTest.php`
Expected: FAIL — class `OidcProviderConfig` not found.

- [ ] **Step 3: Create `OidcProviderConfig.php`**

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Models\Auth\AuthProviderSetting;

class OidcProviderConfig
{
    public function settings(): array
    {
        $provider = $this->provider();
        $stored = $provider?->settings ?? [];

        return [
            'enabled' => $this->enabled($provider),
            'display_name' => $provider?->display_name ?? 'Sign in with Authentik',
            'discovery_url' => $this->stringSetting($stored, 'discovery_url', (string) config('services.oidc.discovery_url', '')),
            'client_id' => $this->stringSetting($stored, 'client_id', (string) config('services.oidc.client_id', '')),
            'client_secret' => (string) config('services.oidc.client_secret', ''), // secret ONLY from env
            'redirect_uri' => $this->stringSetting($stored, 'redirect_uri', (string) config('services.oidc.redirect_uri', '')),
            'scopes' => $this->listSetting($stored, 'scopes', (array) config('services.oidc.scopes', ['openid', 'profile', 'email'])),
            'allowed_groups' => $this->listSetting($stored, 'allowed_groups', (array) config('services.oidc.allowed_groups', ['Zephyrus Users'])),
            'admin_groups' => $this->listSetting($stored, 'admin_groups', (array) config('services.oidc.admin_groups', ['Zephyrus Admins'])),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    public function isPubliclyAvailable(): bool
    {
        $s = $this->settings();

        return $s['enabled'] && $s['discovery_url'] !== '' && $s['client_id'] !== '' && $s['redirect_uri'] !== '';
    }

    public function displayName(): string { return $this->settings()['display_name']; }
    public function discoveryUrl(): string { return $this->settings()['discovery_url']; }
    public function clientId(): string { return $this->settings()['client_id']; }
    public function clientSecret(): string { return $this->settings()['client_secret']; }
    public function redirectUri(): string { return $this->settings()['redirect_uri']; }

    /** @return list<string> */
    public function scopes(): array { return $this->settings()['scopes']; }

    /** @return list<string> */
    public function allowedGroups(): array { return $this->settings()['allowed_groups']; }

    /** @return list<string> */
    public function adminGroups(): array { return $this->settings()['admin_groups']; }

    private function provider(): ?AuthProviderSetting
    {
        try {
            return AuthProviderSetting::query()->where('provider_type', 'oidc')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function enabled(?AuthProviderSetting $provider): bool
    {
        return (bool) config('services.oidc.enabled', false) || (bool) ($provider?->is_enabled ?? false);
    }

    private function stringSetting(array $settings, string $key, string $fallback): string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    /** @return list<string> */
    private function listSetting(array $settings, string $key, array $fallback): array
    {
        $value = $settings[$key] ?? null;
        $items = is_array($value) && $value !== [] ? $value : $fallback;

        return array_values(array_filter(array_map(
            static fn (mixed $i): string => is_string($i) ? trim($i) : '',
            $items,
        )));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcProviderConfigTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/OidcProviderConfig.php tests/Unit/Auth/Oidc/OidcProviderConfigTest.php
git commit -m "feat(auth): OidcProviderConfig with empty-string env fallback"
```

---

### Task 6: OidcDiscoveryService (TDD)

**Files:**
- Create: `app/Services/Auth/Oidc/OidcDiscoveryService.php`
- Test: `tests/Unit/Auth/Oidc/OidcDiscoveryServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('fetches and caches the discovery doc + jwks', function () {
    Http::fake([
        'https://idp/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp',
            'authorization_endpoint' => 'https://idp/authorize',
            'token_endpoint' => 'https://idp/token',
            'jwks_uri' => 'https://idp/jwks',
        ]),
        'https://idp/jwks' => Http::response(['keys' => [['kid' => 'k1']]]),
    ]);

    $svc = new OidcDiscoveryService('https://idp/.well-known/openid-configuration');

    expect($svc->issuer())->toBe('https://idp')
        ->and($svc->tokenEndpoint())->toBe('https://idp/token')
        ->and($svc->jwks()['keys'][0]['kid'])->toBe('k1');
});

it('throws when discovery is malformed', function () {
    Http::fake(['*' => Http::response(['issuer' => 'https://idp'])]); // missing endpoints
    $svc = new OidcDiscoveryService('https://idp/.well-known/openid-configuration');
    $svc->issuer();
})->throws(OidcException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcDiscoveryServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `OidcDiscoveryService.php`** (verbatim port — copy from Aurora `backend/app/Services/Auth/Oidc/OidcDiscoveryService.php`; the file is environment-agnostic). Full content:

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OidcDiscoveryService
{
    private const CACHE_KEY_PREFIX = 'oidc:discovery:';
    private const CACHE_TTL = 3600;

    public function __construct(private readonly string $discoveryUrl) {}

    /** @return array<string, mixed> */
    public function config(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function (): array {
            try {
                $response = Http::timeout(5)->get($this->discoveryUrl);
            } catch (ConnectionException $e) {
                throw new OidcException('discovery_unreachable', $e->getMessage(), $e);
            }
            if ($response->failed()) {
                throw new OidcException('discovery_failed', 'Discovery returned HTTP '.$response->status());
            }
            $config = $response->json() ?? [];
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (! isset($config[$required]) || ! is_string($config[$required])) {
                    throw new OidcException('discovery_malformed', "Missing/invalid '{$required}' in discovery document");
                }
            }
            try {
                $jwks = Http::timeout(5)->get($config['jwks_uri']);
            } catch (ConnectionException $e) {
                throw new OidcException('jwks_unreachable', $e->getMessage(), $e);
            }
            if ($jwks->failed()) {
                throw new OidcException('jwks_failed', 'JWKS returned HTTP '.$jwks->status());
            }
            $body = $jwks->json() ?? [];
            if (! isset($body['keys']) || ! is_array($body['keys'])) {
                throw new OidcException('jwks_malformed', "JWKS response missing 'keys'");
            }
            $config['_jwks'] = $body;

            return $config;
        });
    }

    public function issuer(): string { return (string) $this->config()['issuer']; }
    public function authorizationEndpoint(): string { return (string) $this->config()['authorization_endpoint']; }
    public function tokenEndpoint(): string { return (string) $this->config()['token_endpoint']; }

    /** @return array{keys: list<array<string, mixed>>} */
    public function jwks(): array { return $this->config()['_jwks']; }

    public function flush(): void { Cache::forget($this->cacheKey()); }

    private function cacheKey(): string { return self::CACHE_KEY_PREFIX.sha1($this->discoveryUrl); }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcDiscoveryServiceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/OidcDiscoveryService.php tests/Unit/Auth/Oidc/OidcDiscoveryServiceTest.php
git commit -m "feat(auth): OIDC discovery + JWKS fetch with caching"
```

---

### Task 7: OidcHandshakeStore (TDD)

**Files:**
- Create: `app/Services/Auth/Oidc/OidcHandshakeStore.php`
- Test: `tests/Unit/Auth/Oidc/OidcHandshakeStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Auth\Oidc\OidcHandshakeStore;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('stores and consumes state exactly once', function () {
    $store = new OidcHandshakeStore();
    $state = $store->putState(['nonce' => 'n1', 'code_verifier' => 'v1']);

    expect($store->consumeState($state))->toMatchArray(['nonce' => 'n1', 'code_verifier' => 'v1'])
        ->and($store->consumeState($state))->toBeNull(); // single-use
});

it('returns null for unknown state', function () {
    expect((new OidcHandshakeStore())->consumeState('nope'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcHandshakeStoreTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `OidcHandshakeStore.php`** (port; drop the unused `putCode`/`consumeCode` — no SPA token exchange in Zephyrus)

```php
<?php

namespace App\Services\Auth\Oidc;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OidcHandshakeStore
{
    private const STATE_TTL = 300;
    private const STATE_PREFIX = 'oidc:state:';

    /** @param array{nonce: string, code_verifier: string} $meta */
    public function putState(array $meta): string
    {
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.$state, $meta, self::STATE_TTL);

        return $state;
    }

    /** @return array{nonce: string, code_verifier: string}|null */
    public function consumeState(string $state): ?array
    {
        return Cache::pull(self::STATE_PREFIX.$state);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcHandshakeStoreTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/OidcHandshakeStore.php tests/Unit/Auth/Oidc/OidcHandshakeStoreTest.php
git commit -m "feat(auth): OIDC PKCE/nonce handshake store (single-use state)"
```

---

### Task 8: OidcTokenValidator (TDD)

**Files:**
- Create: `app/Services/Auth/Oidc/OidcTokenValidator.php`
- Test: `tests/Unit/Auth/Oidc/OidcTokenValidatorTest.php`

- [ ] **Step 1: Write the failing test** (mints real RS256 tokens against a generated keypair; stubs the discovery service)

```php
<?php

use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Firebase\JWT\JWT;

function oidcTestKeypair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);
    $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
    $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256', 'n' => $n, 'e' => $e]]];

    return [$privatePem, $jwks];
}

function fakeDiscovery(array $jwks): OidcDiscoveryService
{
    return new class('x', $jwks) extends OidcDiscoveryService {
        public function __construct(string $url, private array $jwksData) { parent::__construct($url); }
        public function issuer(): string { return 'https://idp'; }
        public function jwks(): array { return $this->jwksData; }
    };
}

function mintIdToken(string $privatePem, array $overrides = []): string
{
    $claims = array_merge([
        'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 'sub-1',
        'email' => 'u@example.com', 'name' => 'User One', 'nonce' => 'n1',
        'exp' => time() + 300, 'iat' => time(), 'groups' => ['Zephyrus Users'],
    ], $overrides);

    return JWT::encode($claims, $privatePem, 'RS256', 'test-kid');
}

it('accepts a valid token and extracts claims', function () {
    [$priv, $jwks] = oidcTestKeypair();
    $validator = new OidcTokenValidator(fakeDiscovery($jwks), 'client-123');

    $claims = $validator->validate(mintIdToken($priv), 'n1');

    expect($claims->sub)->toBe('sub-1')
        ->and($claims->email)->toBe('u@example.com')
        ->and($claims->groups)->toContain('Zephyrus Users');
});

it('rejects wrong audience', function () {
    [$priv, $jwks] = oidcTestKeypair();
    (new OidcTokenValidator(fakeDiscovery($jwks), 'client-123'))
        ->validate(mintIdToken($priv, ['aud' => 'someone-else']), 'n1');
})->throws(OidcTokenInvalidException::class);

it('rejects nonce mismatch', function () {
    [$priv, $jwks] = oidcTestKeypair();
    (new OidcTokenValidator(fakeDiscovery($jwks), 'client-123'))
        ->validate(mintIdToken($priv), 'different-nonce');
})->throws(OidcTokenInvalidException::class);

it('rejects an expired token', function () {
    [$priv, $jwks] = oidcTestKeypair();
    (new OidcTokenValidator(fakeDiscovery($jwks), 'client-123'))
        ->validate(mintIdToken($priv, ['exp' => time() - 3600]), 'n1');
})->throws(OidcTokenInvalidException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcTokenValidatorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `OidcTokenValidator.php`** (verbatim port from Aurora — environment-agnostic)

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class OidcTokenValidator
{
    public function __construct(
        private readonly OidcDiscoveryService $discovery,
        private readonly string $audience,
    ) {}

    public function validate(string $idToken, ?string $expectedNonce = null): ValidatedClaims
    {
        $keys = JWK::parseKeySet($this->discovery->jwks());
        JWT::$leeway = 30;

        try {
            $payload = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new OidcTokenInvalidException('signature_invalid', $e->getMessage(), $e);
        }

        if (! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            throw new OidcTokenInvalidException('missing_claim', "Required claim 'exp' missing or non-numeric");
        }

        $issuer = (string) ($payload['iss'] ?? '');
        if ($issuer !== $this->discovery->issuer()) {
            throw new OidcTokenInvalidException('issuer_mismatch', "Expected '{$this->discovery->issuer()}', got '{$issuer}'");
        }

        $audience = $payload['aud'] ?? null;
        $audienceList = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];
        if (! in_array($this->audience, $audienceList, true)) {
            throw new OidcTokenInvalidException('audience_mismatch', "Token audience does not include '{$this->audience}'");
        }

        if ($expectedNonce !== null) {
            $tokenNonce = (string) ($payload['nonce'] ?? '');
            if (! hash_equals($expectedNonce, $tokenNonce)) {
                throw new OidcTokenInvalidException('nonce_mismatch', 'Token nonce does not match stored nonce');
            }
        }

        foreach (['sub', 'email', 'name'] as $required) {
            if (! isset($payload[$required]) || ! is_string($payload[$required]) || $payload[$required] === '') {
                throw new OidcTokenInvalidException('missing_claim', "Required claim '{$required}' missing or empty");
            }
        }

        $groups = [];
        if (isset($payload['groups']) && is_array($payload['groups'])) {
            foreach ($payload['groups'] as $group) {
                if (is_string($group)) {
                    $groups[] = $group;
                }
            }
        }

        return new ValidatedClaims(
            sub: (string) $payload['sub'],
            email: (string) $payload['email'],
            name: (string) $payload['name'],
            groups: $groups,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcTokenValidatorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/OidcTokenValidator.php tests/Unit/Auth/Oidc/OidcTokenValidatorTest.php
git commit -m "feat(auth): OIDC id_token validator (sig/iss/aud/exp/nonce)"
```

---

### Task 9: Driver contracts + registry + AuthentikOidc driver

**Files:**
- Create: `app/Contracts/AuthDriverInterface.php`
- Create: `app/Auth/Drivers/AuthDriverResult.php`
- Create: `app/Auth/Drivers/AuthDriverException.php`
- Create: `app/Auth/AuthDriverRegistry.php`
- Create: `app/Auth/Drivers/AuthentikOidcAuthDriver.php`

- [ ] **Step 1: `app/Auth/Drivers/AuthDriverResult.php`**

```php
<?php

namespace App\Auth\Drivers;

use App\Models\User;

final readonly class AuthDriverResult
{
    /** @param array<string, mixed> $providerClaims */
    public function __construct(
        public User $user,
        public string $driverName,
        public bool $mustChangePassword = false,
        public ?string $providerSubject = null,
        public array $providerClaims = [],
    ) {}
}
```

- [ ] **Step 2: `app/Auth/Drivers/AuthDriverException.php`**

```php
<?php

namespace App\Auth\Drivers;

use Exception;
use Throwable;

class AuthDriverException extends Exception
{
    public const CODE_INVALID_CREDENTIALS = 401;
    public const CODE_ACCOUNT_DISABLED = 403;
    public const CODE_MALFORMED_CREDENTIALS = 422;

    public function __construct(
        string $message,
        int $code,
        public readonly string $driverName,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

- [ ] **Step 3: `app/Contracts/AuthDriverInterface.php`**

```php
<?php

namespace App\Contracts;

use App\Auth\Drivers\AuthDriverResult;

interface AuthDriverInterface
{
    public function name(): string;

    /** @param array<string, mixed> $credentials */
    public function authenticate(array $credentials): AuthDriverResult;

    public function isAvailable(): bool;
}
```

- [ ] **Step 4: `app/Auth/AuthDriverRegistry.php`** (verbatim port)

```php
<?php

namespace App\Auth;

use App\Contracts\AuthDriverInterface;
use InvalidArgumentException;

class AuthDriverRegistry
{
    /** @var array<string, AuthDriverInterface> */
    private array $drivers = [];

    public function register(AuthDriverInterface $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function driver(string $name): AuthDriverInterface
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException(
                "Unknown auth driver: '{$name}'. Registered drivers: ".
                (empty($this->drivers) ? '(none)' : implode(', ', $this->names()))
            );
        }

        return $this->drivers[$name];
    }

    /** @return list<string> */
    public function names(): array { return array_keys($this->drivers); }

    /** @return list<string> */
    public function availableNames(): array
    {
        return array_values(array_filter($this->names(), fn (string $n) => $this->drivers[$n]->isAvailable()));
    }
}
```

- [ ] **Step 5: `app/Auth/Drivers/AuthentikOidcAuthDriver.php`** (adapted — no Sanctum `mfaAuthenticated` field)

```php
<?php

namespace App\Auth\Drivers;

use App\Contracts\AuthDriverInterface;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use Throwable;

class AuthentikOidcAuthDriver implements AuthDriverInterface
{
    public function __construct(
        private readonly OidcReconciliationService $reconciler,
        private readonly OidcProviderConfig $config,
    ) {}

    public function name(): string { return 'authentik-oidc'; }

    public function isAvailable(): bool { return $this->config->isPubliclyAvailable(); }

    public function authenticate(array $credentials): AuthDriverResult
    {
        $claims = $credentials['claims'] ?? null;
        if (! $claims instanceof ValidatedClaims) {
            throw new AuthDriverException(
                'Malformed credentials: expected ValidatedClaims under "claims" key',
                AuthDriverException::CODE_MALFORMED_CREDENTIALS,
                $this->name(),
            );
        }

        try {
            $result = $this->reconciler->reconcile($claims);
        } catch (OidcAccessDeniedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AuthDriverException(
                'OIDC reconciliation failed',
                AuthDriverException::CODE_INVALID_CREDENTIALS,
                $this->name(),
                $e,
            );
        }

        return new AuthDriverResult(
            user: $result['user'],
            driverName: $this->name(),
            mustChangePassword: false,
            providerSubject: $claims->sub,
            providerClaims: [
                'email' => $claims->email,
                'name' => $claims->name,
                'groups' => $claims->groups,
                'reason' => $result['reason'],
            ],
        );
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Contracts/AuthDriverInterface.php app/Auth
git commit -m "feat(auth): auth driver registry + Authentik OIDC driver"
```

---

### Task 10: OidcReconciliationService (TDD)

**Files:**
- Create: `app/Services/Auth/Oidc/OidcReconciliationService.php`
- Test: `tests/Unit/Auth/Oidc/OidcReconciliationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Auth\OidcEmailAlias;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function claims(array $o = []): ValidatedClaims
{
    return new ValidatedClaims(
        sub: $o['sub'] ?? 'sub-1',
        email: $o['email'] ?? 'new@example.com',
        name: $o['name'] ?? 'New User',
        groups: $o['groups'] ?? ['Zephyrus Users'],
    );
}

function reconciler(): OidcReconciliationService
{
    return new OidcReconciliationService(['Zephyrus Users'], ['Zephyrus Admins']);
}

it('links an existing user by email and creates an identity row', function () {
    $u = User::factory()->create(['email' => 'existing@example.com', 'is_active' => true]);

    $res = reconciler()->reconcile(claims(['email' => 'Existing@Example.com']));

    expect($res['reason'])->toBe('linked_by_email')
        ->and($res['user']->id)->toBe($u->id)
        ->and(UserExternalIdentity::where('user_id', $u->id)->where('provider_subject', 'sub-1')->exists())->toBeTrue();
});

it('JIT-creates a user as role=user when in the allowed group', function () {
    $res = reconciler()->reconcile(claims(['email' => 'jit@example.com', 'groups' => ['Zephyrus Users']]));

    expect($res['reason'])->toBe('created_jit')
        ->and($res['user']->role)->toBe('user')
        ->and($res['user']->must_change_password)->toBeFalse()
        ->and($res['user']->is_active)->toBeTrue()
        ->and($res['user']->username)->not->toBeEmpty();
});

it('JIT-creates a user as role=admin when in the admin group', function () {
    $res = reconciler()->reconcile(claims(['email' => 'boss@example.com', 'groups' => ['Zephyrus Admins']]));
    expect($res['user']->role)->toBe('admin');
});

it('denies an unknown user who is in no allowed group', function () {
    reconciler()->reconcile(claims(['email' => 'stranger@example.com', 'groups' => ['Other']]));
})->throws(OidcAccessDeniedException::class);

it('resolves an email alias to an existing privileged account', function () {
    $admin = User::factory()->create(['email' => 'admin@acumenus.net', 'is_active' => true]);
    OidcEmailAlias::create(['alias_email' => 'me@personal.com', 'canonical_email' => 'admin@acumenus.net']);

    $res = reconciler()->reconcile(claims(['email' => 'me@personal.com', 'groups' => []]));

    expect($res['reason'])->toBe('linked_by_alias')->and($res['user']->id)->toBe($admin->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcReconciliationServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `OidcReconciliationService.php`** (adapted: varchar `role`, no Spatie, username generation, `prod` schema, admin-group support)

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Models\Auth\OidcEmailAlias;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OidcReconciliationService
{
    private const PROVIDER = 'authentik';

    /**
     * @param list<string> $allowedGroups
     * @param list<string> $adminGroups
     */
    public function __construct(
        private readonly array $allowedGroups = ['Zephyrus Users'],
        private readonly array $adminGroups = ['Zephyrus Admins'],
    ) {}

    /** @return array{user: User, reason: string} */
    public function reconcile(ValidatedClaims $claims): array
    {
        return DB::transaction(function () use ($claims): array {
            $identity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_subject', $claims->sub)
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                if ($user === null) {
                    throw new OidcAccessDeniedException('linked_user_missing', 'Linked Zephyrus user no longer exists.');
                }
                $this->assertActive($user);

                return ['user' => $user, 'reason' => 'linked_by_sub'];
            }

            $canonical = strtolower($claims->email);

            $user = User::query()->whereRaw('lower(email) = ?', [$canonical])->first();
            if ($user !== null) {
                $this->assertActive($user);
                $this->link($user->id, $claims);

                return ['user' => $user, 'reason' => 'linked_by_email'];
            }

            $aliased = OidcEmailAlias::canonicalFor($canonical);
            if ($aliased !== null) {
                $user = User::query()->whereRaw('lower(email) = ?', [$aliased])->first();
                if ($user !== null) {
                    $this->assertActive($user);
                    $this->link($user->id, $claims);

                    return ['user' => $user, 'reason' => 'linked_by_alias'];
                }
            }

            if (! $this->inAnyGroup($claims->groups, [...$this->allowedGroups, ...$this->adminGroups])) {
                throw new OidcAccessDeniedException('not_in_allowed_group', 'User is not in an allowed Zephyrus group.');
            }

            $role = $this->inAnyGroup($claims->groups, $this->adminGroups) ? 'admin' : 'user';

            $user = User::query()->create([
                'name' => $claims->name,
                'email' => $canonical,
                'username' => $this->uniqueUsername($canonical),
                'password' => bcrypt(Str::random(64)),
                'must_change_password' => false,
                'role' => $role,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->link($user->id, $claims);

            return ['user' => $user, 'reason' => 'created_jit'];
        });
    }

    private function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw new OidcAccessDeniedException('account_disabled', 'Linked Zephyrus user is disabled.');
        }
    }

    private function link(int $userId, ValidatedClaims $claims): void
    {
        UserExternalIdentity::query()->create([
            'user_id' => $userId,
            'provider' => self::PROVIDER,
            'provider_subject' => $claims->sub,
            'provider_email_at_link' => $claims->email,
            'linked_at' => now(),
        ]);
    }

    /** Mirror RegisteredUserController username derivation. */
    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_-]/', '', strtolower(explode('@', $email)[0])) ?: 'user';
        $username = $base;
        $i = 1;
        while (User::query()->where('username', $username)->exists()) {
            $username = $base.$i;
            $i++;
        }

        return $username;
    }

    /**
     * @param list<string> $tokenGroups
     * @param list<string> $needles
     */
    private function inAnyGroup(array $tokenGroups, array $needles): bool
    {
        foreach ($needles as $g) {
            if (in_array($g, $tokenGroups, true)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Auth/Oidc/OidcReconciliationServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/Oidc/OidcReconciliationService.php tests/Unit/Auth/Oidc/OidcReconciliationServiceTest.php
git commit -m "feat(auth): OIDC reconciliation (link/JIT, group-gated, alias, never superuser)"
```

---

### Task 11: Provider bindings + OidcController + routes (TDD feature)

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Http/Controllers/Auth/OidcController.php`
- Modify: `routes/auth.php`
- Test: `tests/Feature/Auth/OidcControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Models\Auth\AuthProviderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function enableOidc(): void
{
    config()->set('services.oidc.enabled', true);
    config()->set('services.oidc.client_id', 'client-123');
    config()->set('services.oidc.client_secret', 'secret');
    config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
    config()->set('services.oidc.redirect_uri', 'https://app.test/auth/oidc/callback');
    Cache::flush();
    Http::fake([
        'https://idp/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp',
            'authorization_endpoint' => 'https://idp/authorize',
            'token_endpoint' => 'https://idp/token',
            'jwks_uri' => 'https://idp/jwks',
        ]),
        'https://idp/jwks' => Http::response(['keys' => []]),
    ]);
}

it('returns 404 on redirect when OIDC is disabled', function () {
    config()->set('services.oidc.enabled', false);
    $this->get('/auth/oidc/redirect')->assertNotFound();
});

it('redirects (302) to the Authentik authorize endpoint when enabled', function () {
    enableOidc();
    $res = $this->get('/auth/oidc/redirect');
    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('https://idp/authorize')
        ->toContain('code_challenge_method=S256')
        ->toContain('client_id=client-123');
});

it('rejects a callback with an unknown state', function () {
    enableOidc();
    $this->get('/auth/oidc/callback?state=bogus&code=abc')->assertStatus(400);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auth/OidcControllerTest.php`
Expected: FAIL — route `/auth/oidc/redirect` not defined (404 for the wrong reason / route missing).

- [ ] **Step 3: Add bindings to `app/Providers/AppServiceProvider.php`** — in `register()` add:

```php
use App\Auth\AuthDriverRegistry;
use App\Auth\Drivers\AuthentikOidcAuthDriver;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\OidcTokenValidator;

// inside register():
$this->app->singleton(OidcProviderConfig::class);

$this->app->bind(OidcDiscoveryService::class, fn ($app) => new OidcDiscoveryService(
    $app->make(OidcProviderConfig::class)->discoveryUrl()
));

$this->app->bind(OidcTokenValidator::class, fn ($app) => new OidcTokenValidator(
    $app->make(OidcDiscoveryService::class),
    $app->make(OidcProviderConfig::class)->clientId()
));

$this->app->bind(OidcReconciliationService::class, fn ($app) => new OidcReconciliationService(
    $app->make(OidcProviderConfig::class)->allowedGroups(),
    $app->make(OidcProviderConfig::class)->adminGroups()
));

$this->app->singleton(OidcHandshakeStore::class);

$this->app->singleton(AuthDriverRegistry::class, function ($app) {
    $registry = new AuthDriverRegistry();
    $registry->register($app->make(AuthentikOidcAuthDriver::class));

    return $registry;
});
```

- [ ] **Step 4: Create `app/Http/Controllers/Auth/OidcController.php`** (web-guard login; mirrors `AuthenticatedSessionController::store`)

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AuthDriverRegistry;
use App\Http\Controllers\Controller;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OidcController extends Controller
{
    public function redirect(
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcProviderConfig $config,
    ): Response {
        $this->ensureEnabled($config);

        try {
            $authorize = $discovery->authorizationEndpoint();
        } catch (OidcException $e) {
            return $this->fail('discovery_failed', $e);
        }

        $nonce = Str::random(32);
        $verifier = $this->codeVerifier();
        $state = $store->putState(['nonce' => $nonce, 'code_verifier' => $verifier]);

        $params = [
            'response_type' => 'code',
            'client_id' => $config->clientId(),
            'redirect_uri' => $config->redirectUri(),
            'scope' => implode(' ', $config->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ];

        return redirect()->away($authorize.'?'.http_build_query($params));
    }

    public function callback(
        Request $request,
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcTokenValidator $validator,
        AuthDriverRegistry $registry,
        OidcProviderConfig $config,
    ): RedirectResponse {
        $this->ensureEnabled($config);

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if ($state === '' || $code === '') {
            abort(400, 'missing_parameters');
        }

        $meta = $store->consumeState($state);
        if ($meta === null) {
            abort(400, 'unknown_state');
        }

        try {
            $tokenResponse = Http::asForm()->post($discovery->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config->redirectUri(),
                'client_id' => $config->clientId(),
                'client_secret' => $config->clientSecret(),
                'code_verifier' => $meta['code_verifier'],
            ]);
        } catch (\Throwable $e) {
            return $this->fail('token_exchange_failed', $e);
        }

        if ($tokenResponse->failed()) {
            return $this->fail('token_exchange_failed', null);
        }

        $idToken = (string) ($tokenResponse->json('id_token') ?? '');
        if ($idToken === '') {
            return $this->fail('missing_id_token', null);
        }

        try {
            $claims = $validator->validate($idToken, $meta['nonce']);
        } catch (OidcTokenInvalidException $e) {
            return $this->fail($e->reason, $e);
        }

        try {
            $result = $registry->driver('authentik-oidc')->authenticate(['claims' => $claims]);
        } catch (OidcAccessDeniedException $e) {
            return $this->fail($e->reason, $e);
        }

        $user = $result->user;

        // Mirror AuthenticatedSessionController::store session setup (web guard).
        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put('username', $user->username);
        if ($user->workflow_preference === null) {
            $user->update(['workflow_preference' => 'superuser']);
        }
        $request->session()->put('user_id', $user->id);

        return redirect()->intended(route('dashboard'));
    }

    private function ensureEnabled(OidcProviderConfig $config): void
    {
        if (! $config->isPubliclyAvailable()) {
            abort(404);
        }
    }

    private function fail(string $reason, ?\Throwable $e): RedirectResponse
    {
        if ($e !== null) {
            Log::warning('OIDC failure', ['reason' => $reason, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'Single sign-on failed. Please try again or use your password.');
    }

    private function codeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 5: Add routes to `routes/auth.php`** — inside the existing `Route::middleware('guest')->group(...)` block add:

```php
use App\Http\Controllers\Auth\OidcController;

Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('auth.oidc.redirect');
Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('auth.oidc.callback');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auth/OidcControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Http/Controllers/Auth/OidcController.php routes/auth.php tests/Feature/Auth/OidcControllerTest.php
git commit -m "feat(auth): OIDC redirect+callback controller, DI bindings, routes"
```

---

### Task 12: Admin settings controller + seeder (TDD feature)

**Files:**
- Create: `app/Http/Controllers/Admin/AuthProviderController.php`
- Create: `database/seeders/AuthProviderSettingSeeder.php`
- Modify: `routes/auth.php` (admin route, superuser-gated)
- Test: `tests/Feature/Admin/AuthProviderControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Models\Auth\AuthProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids non-admins from reading provider settings', function () {
    $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
    $this->actingAs($user)->getJson('/admin/auth-providers/oidc')->assertForbidden();
});

it('lets an admin read settings with the secret masked', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    AuthProviderSetting::create([
        'provider_type' => 'oidc',
        'display_name' => 'Sign in with Authentik',
        'is_enabled' => true,
        'settings' => ['client_id' => 'abc', 'client_secret' => 'should-never-appear'],
    ]);

    $res = $this->actingAs($admin)->getJson('/admin/auth-providers/oidc')->assertOk();
    $res->assertJsonPath('settings.client_id', 'abc');
    expect(json_encode($res->json()))->not->toContain('should-never-appear');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/AuthProviderControllerTest.php`
Expected: FAIL — route missing.

- [ ] **Step 3: Create `app/Http/Controllers/Admin/AuthProviderController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AuthProviderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthProviderController extends Controller
{
    private const SECRET_KEYS = ['client_secret'];

    public function show(string $type): JsonResponse
    {
        $row = AuthProviderSetting::query()->where('provider_type', $type)->first();

        return response()->json([
            'provider_type' => $type,
            'is_enabled' => (bool) ($row?->is_enabled ?? false),
            'display_name' => $row?->display_name,
            'settings' => $this->mask($row?->settings ?? []),
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'display_name' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
        ]);

        $row = AuthProviderSetting::query()->firstOrNew(['provider_type' => $type]);
        $merged = array_merge($row->settings ?? [], $validated['settings'] ?? []);
        // Never persist secrets to the DB — they live in env only.
        foreach (self::SECRET_KEYS as $k) {
            unset($merged[$k]);
        }

        $row->fill([
            'display_name' => $validated['display_name'] ?? $row->display_name ?? 'Sign in with Authentik',
            'is_enabled' => $validated['is_enabled'] ?? $row->is_enabled ?? false,
            'settings' => $merged,
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->show($type);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function mask(array $settings): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (array_key_exists($k, $settings)) {
                $settings[$k] = '••••••••';
            }
        }

        return $settings;
    }
}
```

- [ ] **Step 4: Add the admin route to `routes/auth.php`** — in the `['web', 'auth']` group, gated to admin role via an inline closure middleware:

```php
use App\Http\Controllers\Admin\AuthProviderController;

Route::middleware(function ($request, $next) {
    abort_unless(in_array($request->user()?->role, ['admin', 'superuser'], true), 403);

    return $next($request);
})->group(function () {
    Route::get('admin/auth-providers/{type}', [AuthProviderController::class, 'show'])->name('admin.auth-providers.show');
    Route::put('admin/auth-providers/{type}', [AuthProviderController::class, 'update'])->name('admin.auth-providers.update');
});
```

- [ ] **Step 5: Create `database/seeders/AuthProviderSettingSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\Auth\AuthProviderSetting;
use Illuminate\Database\Seeder;

class AuthProviderSettingSeeder extends Seeder
{
    public function run(): void
    {
        AuthProviderSetting::query()->updateOrCreate(
            ['provider_type' => 'oidc'],
            [
                'display_name' => 'Sign in with Authentik',
                'is_enabled' => false, // flip on after secrets are in env + Authentik app exists
                'settings' => [
                    'discovery_url' => 'https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration',
                    'redirect_uri' => 'https://zephyrus.acumenus.net/auth/oidc/callback',
                    'scopes' => ['openid', 'profile', 'email', 'groups'],
                    'allowed_groups' => ['Zephyrus Users'],
                    'admin_groups' => ['Zephyrus Admins'],
                    // client_id set via admin endpoint or env; client_secret ONLY in env.
                ],
            ],
        );
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Admin/AuthProviderControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/AuthProviderController.php database/seeders/AuthProviderSettingSeeder.php routes/auth.php tests/Feature/Admin/AuthProviderControllerTest.php
git commit -m "feat(auth): superuser-gated OIDC settings endpoint (secrets masked) + seeder"
```

---

### Task 13: Frontend — expose flag + SSO button (TDD)

**Files:**
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (`create()` only — additive)
- Modify: `resources/js/Pages/Auth/Login.jsx`
- Test: `resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx`

- [ ] **Step 1: Add props in `AuthenticatedSessionController::create()`** — replace the `Inertia::render('Auth/Login', [...])` array with (keeping existing keys):

```php
public function create(): Response
{
    $oidc = app(\App\Services\Auth\Oidc\OidcProviderConfig::class);

    return Inertia::render('Auth/Login', [
        'canResetPassword' => Route::has('password.request'),
        'status' => session('status'),
        'oidcEnabled' => $oidc->isPubliclyAvailable(),
        'oidcLabel' => $oidc->displayName(),
    ]);
}
```

- [ ] **Step 2: Write the failing Vitest test**

```jsx
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...p }) => <a {...p}>{children}</a>,
  useForm: () => ({ data: {}, setData: vi.fn(), post: vi.fn(), processing: false, errors: {} }),
}));

import Login from '../Login.jsx';

describe('Login Authentik SSO button', () => {
  it('shows the SSO button when oidcEnabled is true', () => {
    render(<Login status={null} canResetPassword={false} oidcEnabled={true} oidcLabel="Sign in with Authentik" />);
    expect(screen.getByRole('link', { name: /authentik/i })).toHaveAttribute('href', '/auth/oidc/redirect');
  });

  it('hides the SSO button when oidcEnabled is false', () => {
    render(<Login status={null} canResetPassword={false} oidcEnabled={false} />);
    expect(screen.queryByRole('link', { name: /authentik/i })).toBeNull();
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npx vitest run resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx`
Expected: FAIL — no element with accessible name /authentik/.

- [ ] **Step 4: Edit `resources/js/Pages/Auth/Login.jsx`**

(a) Update the component signature:
```jsx
export default function Login({ status, canResetPassword, oidcEnabled = false, oidcLabel = 'Sign in with Authentik' }) {
```

(b) Immediately AFTER the `</form>` closing tag (before the `{/* Divider + dark mode */}` block), insert:
```jsx
                            {oidcEnabled && (
                                <div className="mt-5">
                                    <div className="relative flex items-center">
                                        <div className="flex-grow border-t border-slate-200 dark:border-slate-700/50" />
                                        <span className="mx-3 text-xs text-slate-400">or</span>
                                        <div className="flex-grow border-t border-slate-200 dark:border-slate-700/50" />
                                    </div>
                                    <a
                                        href="/auth/oidc/redirect"
                                        className="mt-4 inline-flex w-full items-center justify-center gap-2 h-12 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors"
                                    >
                                        <Icon icon="lucide:shield-check" className="w-4 h-4 text-indigo-500" />
                                        {oidcLabel}
                                    </a>
                                </div>
                            )}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx`
Expected: PASS (2 tests).

- [ ] **Step 6: Verify the existing login form is untouched (auth-system.md compliance)**

Run: `git diff resources/js/Pages/Auth/Login.jsx`
Confirm: only the signature line + the additive `{oidcEnabled && ...}` block changed; "Create Account" CTA, password fields, forgot-password link all present and unmodified.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/AuthenticatedSessionController.php resources/js/Pages/Auth/Login.jsx resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx
git commit -m "feat(auth): additive 'Sign in with Authentik' button on login page"
```

---

### Task 14: Authentik provisioning script

**Files:**
- Create: `scripts/authentik/provision_zephyrus_oidc.py`

- [ ] **Step 1: Create the script** (adapted from the Medgnosis/Parthenon provisioning pattern; idempotent; prints the client_id/secret to paste into prod env)

```python
#!/usr/bin/env python3
"""Provision a zephyrus-oidc OAuth2/OpenID app in Authentik (Acropolis).

Idempotent: creates (or reuses) the OAuth2 provider, the `zephyrus-oidc`
application, and the `Zephyrus Users` / `Zephyrus Admins` groups, then binds the
groups to the application as an access policy. Prints client_id + client_secret.

Run on beastmode (Authentik reachable at https://auth.acumenus.net):
    AUTHENTIK_TOKEN=$(docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN) \
        python3 scripts/authentik/provision_zephyrus_oidc.py
"""
import os
import sys
import requests

BASE = "https://auth.acumenus.net/api/v3"
REDIRECT = "https://zephyrus.acumenus.net/auth/oidc/callback"
APP_SLUG = "zephyrus-oidc"
APP_NAME = "Zephyrus (OIDC)"
GROUPS = ["Zephyrus Users", "Zephyrus Admins"]

token = os.environ.get("AUTHENTIK_TOKEN")
if not token:
    sys.exit("Set AUTHENTIK_TOKEN (docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN)")

s = requests.Session()
s.headers.update({"Authorization": f"Bearer {token}", "Content-Type": "application/json"})


def get_list(path, **params):
    r = s.get(f"{BASE}{path}", params=params, timeout=15)
    r.raise_for_status()
    return r.json()["results"]


def ensure_group(name):
    existing = get_list("/core/groups/", search=name)
    for g in existing:
        if g["name"] == name:
            return g["pk"]
    r = s.post(f"{BASE}/core/groups/", json={"name": name})
    r.raise_for_status()
    return r.json()["pk"]


def default_flows():
    auth = get_list("/flow/instances/", slug="default-provider-authorization-implicit-consent")
    return auth[0]["pk"] if auth else None


def signing_key():
    keys = get_list("/crypto/certificatekeypairs/", search="authentik")
    return keys[0]["pk"] if keys else None


def ensure_provider():
    for p in get_list("/providers/oauth2/", search=APP_SLUG):
        if p["name"] == APP_SLUG:
            return p
    payload = {
        "name": APP_SLUG,
        "authorization_flow": default_flows(),
        "client_type": "confidential",
        "redirect_uris": REDIRECT,
        "sub_mode": "user_email",
        "include_claims_in_id_token": True,
        "signing_key": signing_key(),
        "property_mappings": [
            pm["pk"] for pm in get_list("/propertymappings/provider/scope/")
            if pm["scope_name"] in ("openid", "email", "profile", "groups")
        ],
    }
    r = s.post(f"{BASE}/providers/oauth2/", json=payload)
    r.raise_for_status()
    return r.json()


def ensure_application(provider_pk):
    for a in get_list("/core/applications/", search=APP_SLUG):
        if a["slug"] == APP_SLUG:
            return a
    r = s.post(f"{BASE}/core/applications/", json={
        "name": APP_NAME, "slug": APP_SLUG, "provider": provider_pk,
        "meta_launch_url": "https://zephyrus.acumenus.net/",
    })
    r.raise_for_status()
    return r.json()


def bind_group(app_pk, group_pk):
    existing = get_list("/policies/bindings/", target=app_pk)
    if any(b.get("group") == group_pk for b in existing):
        return
    s.post(f"{BASE}/policies/bindings/", json={
        "target": app_pk, "group": group_pk, "order": 0, "enabled": True,
    }).raise_for_status()


def main():
    group_pks = {name: ensure_group(name) for name in GROUPS}
    provider = ensure_provider()
    app = ensure_application(provider["pk"])
    for name in GROUPS:
        bind_group(app["pk"], group_pks[name])

    print("=== zephyrus-oidc provisioned ===")
    print(f"discovery_url : https://auth.acumenus.net/application/o/{APP_SLUG}/.well-known/openid-configuration")
    print(f"redirect_uri  : {REDIRECT}")
    print(f"OIDC_CLIENT_ID={provider['client_id']}")
    print(f"OIDC_CLIENT_SECRET={provider['client_secret']}")
    print("Groups:", group_pks)
    print("Put intended users in the 'Zephyrus Users' (or 'Zephyrus Admins') group.")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Syntax-check the script (do NOT run against Authentik yet)**

Run: `python3 -m py_compile scripts/authentik/provision_zephyrus_oidc.py && echo OK`
Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add scripts/authentik/provision_zephyrus_oidc.py
git commit -m "feat(auth): Authentik zephyrus-oidc provisioning script"
```

---

### Task 15: Full suite + final wiring

- [ ] **Step 1: Run the whole backend suite**

Run: `php artisan test --filter=Oidc; php artisan test tests/Feature/Admin/AuthProviderControllerTest.php`
Expected: all green.

- [ ] **Step 2: Run the frontend tests + strict build**

Run: `npx vitest run resources/js/Pages/Auth/__tests__/Login.oidc.test.jsx && npx vite build`
Expected: tests pass; build succeeds.

- [ ] **Step 3: Run Pint**

Run: `vendor/bin/pint app/Auth app/Services/Auth app/Http/Controllers/Auth/OidcController.php app/Http/Controllers/Admin/AuthProviderController.php app/Models/Auth`
Expected: no errors.

- [ ] **Step 4: Verify routes are wired**

Run: `php artisan route:list --name=oidc; php artisan route:list --name=auth-providers`
Expected: `auth.oidc.redirect`, `auth.oidc.callback`, `admin.auth-providers.show/update` listed.

- [ ] **Step 5: Commit any formatting**

```bash
git add -A
git commit -m "chore(auth): pint formatting for OIDC stack" || echo "nothing to format"
```

---

## Deployment runbook (post-merge, on /var/www/Zephyrus — NOT part of TDD tasks)

1. `git pull` (or deploy script) into `/var/www/Zephyrus`.
2. `composer install --no-dev --optimize-autoloader`.
3. Migrate the 3 new tables (scoped `--path`, never bare `migrate --force`):
   `php artisan migrate --path=database/migrations/2026_06_21_000001_create_auth_provider_settings_table.php --path=database/migrations/2026_06_21_000002_create_user_external_identities_table.php --path=database/migrations/2026_06_21_000003_create_oidc_email_aliases_table.php`
4. `php artisan db:seed --class=Database\\Seeders\\AuthProviderSettingSeeder` (creates the disabled `oidc` row).
5. On beastmode: `AUTHENTIK_TOKEN=$(docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN) python3 scripts/authentik/provision_zephyrus_oidc.py` → capture `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET`.
6. Add to `/var/www/Zephyrus/.env` (quote spaced values):
   ```
   OIDC_ENABLED=true
   OIDC_CLIENT_ID=<from script>
   OIDC_CLIENT_SECRET=<from script>
   OIDC_ALLOWED_GROUPS="Zephyrus Users"
   OIDC_ADMIN_GROUPS="Zephyrus Admins"
   ```
7. `npx vite build` (or `./deploy.sh --frontend`).
8. `php artisan config:clear && php artisan config:cache`.
9. **`sudo -A chown www-data:www-data bootstrap/cache/config.php .env`** (artisan-as-smudoshi cache-ownership gotcha — caused the 2026-06-21 500s).
10. Put intended people in the `Zephyrus Users` / `Zephyrus Admins` Authentik group.
11. Verify: `curl -sS -o /dev/null -w "%{http_code}" https://zephyrus.acumenus.net/auth/oidc/redirect` → **302** to `auth.acumenus.net`; login page shows the button; complete one real browser round-trip.

---

## Self-Review

**Spec coverage:**
- §3 Authentik config → Task 14 (script) + runbook. ✓
- §4.1 driver registry → Task 9. ✓ (local login intentionally not rerouted — documented deviation)
- §4.2 OIDC services → Tasks 4–8, 10. ✓
- §4.3 controllers → Task 11 (OidcController), Task 12 (AuthProviderController). ✓
- §4.4 routes → Tasks 11, 12. ✓
- §5 provisioning policy → Task 10 (link/JIT/alias/deny, role from group, never superuser). ✓
- §6 migrations/models → Tasks 2, 3. ✓
- §7 frontend additive button → Task 13. ✓
- §8 ships disabled, env, deploy + chown → Tasks 1, 12 seeder, runbook. ✓
- §9 security checklist → Task 8 (token), Task 7 (state), Task 12 (masking), Task 10 (group gate). ✓
- §11 testing → unit + feature + vitest across tasks. ✓

**Placeholder scan:** none — every code/test step has full content.

**Type consistency:** `OidcProviderConfig` exposes `discoveryUrl()/clientId()/allowedGroups()/adminGroups()/displayName()/isPubliclyAvailable()` — all used consistently in Tasks 11/13. `OidcReconciliationService::reconcile()` returns `{user, reason}` — consumed identically in the driver (Task 9) and tests (Task 10). `ValidatedClaims(sub,email,name,groups)` consistent across Tasks 4/8/10. `AuthDriverResult(user, driverName, mustChangePassword, providerSubject, providerClaims)` consistent Task 9. Handshake `putState/consumeState` consistent Tasks 7/11.
