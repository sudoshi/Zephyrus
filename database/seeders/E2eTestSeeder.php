<?php

namespace Database\Seeders;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Auth\UserExternalIdentity;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\ExternalIdentityEventRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/** Provision only the deterministic browser-test actor and non-PHI RTDC fixtures. */
class E2eTestSeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.pgsql.database');
        if (! app()->environment('testing') || ! preg_match('/^zephyrus_test(?:_[a-z0-9]+)?$/', $database)) {
            throw new RuntimeException('E2eTestSeeder may run only against an isolated test database.');
        }

        $username = trim((string) env('TEST_USERNAME'));
        $password = (string) env('TEST_PASSWORD');
        if (! preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) || strlen($password) < 16) {
            throw new RuntimeException('TEST_USERNAME and a 16+ character TEST_PASSWORD are required.');
        }

        User::query()->updateOrCreate(
            ['username' => $username],
            [
                'name' => 'Browser Test Administrator',
                'email' => $username.'@zephyrus.test',
                'password' => Hash::make($password),
                'workflow_preference' => 'superuser',
                // Test-only root profile exercises every capability boundary;
                // no production or demo seeder may provision this role.
                'role' => 'super_admin',
                'is_active' => true,
                'must_change_password' => false,
                'auth_session_version' => 0,
            ],
        );

        $lifecycleTarget = User::query()->updateOrCreate(
            ['username' => 'e2e_lifecycle_target'],
            [
                'name' => 'Browser Lifecycle Target',
                'email' => 'e2e_lifecycle_target@zephyrus.test',
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'workflow_preference' => 'superuser',
                'role' => 'user',
                'is_active' => false,
                'must_change_password' => true,
                'auth_session_version' => 1,
                'deactivated_at' => now(),
            ],
        );

        $externalIdentity = UserExternalIdentity::query()->firstOrCreate(
            [
                'provider' => 'authentik',
                'provider_subject' => 'e2e-lifecycle-subject',
            ],
            [
                'user_id' => $lifecycleTarget->id,
                'provider_email_at_link' => 'e2e_lifecycle_target@zephyrus.test',
                'linked_at' => now(),
                'is_active' => true,
            ],
        );

        if ($externalIdentity->wasRecentlyCreated) {
            app(ExternalIdentityEventRecorder::class)->record(
                $externalIdentity,
                'linked',
                null,
                'Deterministic browser-test identity fixture created.',
                ['source' => 'e2e_test_seeder'],
            );
        }

        $organization = Organization::query()->updateOrCreate(
            ['organization_key' => 'E2E_HEALTH_SYSTEM'],
            [
                'name' => 'E2E Health System',
                'short_name' => 'E2E Health',
                'kind' => 'idn',
                'metadata' => ['fixture' => 'browser_test'],
            ],
        );
        $facility = Facility::query()->updateOrCreate(
            ['facility_key' => 'E2E_MEDICAL_CENTER'],
            [
                'organization_id' => $organization->organization_id,
                'facility_name' => 'E2E Medical Center',
                'short_name' => 'E2E Medical',
                'idn_role' => 'community_hospital',
                'review_status' => 'client_verified',
                'is_active' => true,
                'metadata' => ['fixture' => 'browser_test'],
            ],
        );
        app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'e2e.fhir.source',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'source_name' => 'E2E FHIR Source',
            'vendor' => 'E2E Vendor',
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'go_live_status' => 'testing',
            'metadata' => ['fixture' => 'browser_test'],
        ]);

        $this->call(RtdcSeeder::class);
    }
}
