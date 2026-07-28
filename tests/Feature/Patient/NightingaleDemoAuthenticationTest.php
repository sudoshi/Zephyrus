<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Nightingale\Demo\NightingaleDemoCohort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NightingaleDemoAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_owned_demo_alias_authenticates_without_changing_email_authentication(): void
    {
        $this->enablePatientAuth();
        $demo = $this->demoPrincipal('demo1');
        $email = $this->ordinaryPrincipal();

        $demoResponse = $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1',
            'password' => 'Synthetic-Test-Credential-Only-91!',
            'device' => ['platform' => 'ios'],
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $session = PatientSession::query()
            ->where('session_uuid', (string) $demoResponse->json('data.session_uuid'))
            ->firstOrFail();
        $this->assertSame($demo->getKey(), $session->principal_id);
        $this->assertSame('nightingale_demo_password', $session->auth_method);
        $this->assertSame('synthetic_demo', $session->assurance_level);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $demo->getKey(),
            'patient_session_id' => $session->getKey(),
            'event_type' => 'patient.auth.token_issued',
            'outcome' => 'succeeded',
        ]);

        $this->postJson('/api/patient/v1/auth/token', [
            'email' => $email->email,
            'password' => 'Synthetic-Email-Credential-Only-82!',
        ])->assertOk();
    }

    public function test_alias_is_exact_and_out_of_range_or_confusable_values_fail_validation(): void
    {
        $this->enablePatientAuth();

        foreach (['demo0', 'demo6', 'demo01', 'demo１'] as $alias) {
            $this->postJson('/api/patient/v1/auth/token', [
                'email' => $alias,
                'password' => 'Synthetic-Test-Credential-Only-91!',
            ])->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['errors' => ['email']]);
        }

        // This is a syntactically valid email, not a demo alias. It follows
        // the ordinary unknown-email path without broadening alias matching.
        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1@example.test',
            'password' => 'Synthetic-Test-Credential-Only-91!',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_foreign_inactive_or_duplicate_aliases_deny_as_invalid_credentials(): void
    {
        $this->enablePatientAuth();
        $this->demoPrincipal('demo1', ['owner' => 'foreign-owner']);
        $this->demoPrincipal('demo2', status: 'suspended');
        $this->demoPrincipal('demo3');
        $this->demoPrincipal('demo3');

        foreach (['demo1', 'demo2', 'demo3', 'demo4'] as $alias) {
            $this->postJson('/api/patient/v1/auth/token', [
                'email' => $alias,
                'password' => 'Synthetic-Test-Credential-Only-91!',
            ])->assertUnauthorized()
                ->assertJsonPath('error.code', 'invalid_credentials')
                ->assertJsonPath('error.message', 'The patient credentials could not be verified.');
        }

        $this->assertSame(0, PatientSession::query()->count());
    }

    public function test_wrong_demo_password_does_not_record_alias_or_principal(): void
    {
        $this->enablePatientAuth();
        $principal = $this->demoPrincipal('demo5');

        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo5',
            'password' => 'Wrong-Synthetic-Test-Credential-39!',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');

        $event = PatientAccessAuditEvent::query()
            ->where('event_type', 'patient.auth.token_denied')
            ->firstOrFail();
        $this->assertNull($event->principal_id);
        $this->assertStringNotContainsString('demo5', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame(0, $principal->tokens()->count());
    }

    public function test_demo1_requires_the_exact_reference_sample_adoption_lineage(): void
    {
        $this->enablePatientAuth();
        $this->demoPrincipal('demo1', [
            'reference_sample_adoption' => [
                'adopted_from_owner' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
                'adopted_patient_ref' => NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF,
                'source_template_product' => NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT,
                'source_template_owner' => 'unexpected-source-owner',
                'source_mode' => NightingaleDemoCohort::REFERENCE_SAMPLE_MODE,
            ],
        ]);

        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'demo1',
            'password' => 'Synthetic-Test-Credential-Only-91!',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials')
            ->assertJsonPath('error.message', 'The patient credentials could not be verified.');
        $this->assertSame(0, PatientSession::query()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function demoPrincipal(
        string $alias,
        array $overrides = [],
        string $status = 'active',
    ): PatientPrincipal {
        $provisioning = array_merge([
            'product' => NightingaleDemoCohort::PRODUCT,
            'environment_class' => NightingaleDemoCohort::ENVIRONMENT_CLASS,
            'owner' => NightingaleDemoCohort::OWNER,
            'cohort_version' => NightingaleDemoCohort::VERSION,
            'demo_username' => $alias,
            'clinical_use_permitted' => false,
            'synthetic' => true,
            ...($alias === 'demo1' ? [
                'reference_sample_adoption' => [
                    'adopted_from_owner' => NightingaleDemoCohort::REFERENCE_SAMPLE_OWNER,
                    'adopted_patient_ref' => NightingaleDemoCohort::REFERENCE_SAMPLE_PATIENT_REF,
                    'source_template_product' => NightingaleDemoCohort::REFERENCE_SOURCE_PRODUCT,
                    'source_template_owner' => NightingaleDemoCohort::REFERENCE_SOURCE_OWNER,
                    'source_mode' => NightingaleDemoCohort::REFERENCE_SAMPLE_MODE,
                ],
            ] : []),
        ], $overrides);

        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Synthetic Nightingale Authentication Test',
            'email' => 'synthetic-'.$alias.'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Synthetic-Test-Credential-Only-91!',
            'status' => $status,
            'is_active' => $status === 'active',
            'preferences' => ['provisioning' => $provisioning],
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    private function ordinaryPrincipal(): PatientPrincipal
    {
        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Ordinary Synthetic Patient',
            'email' => 'ordinary-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Synthetic-Email-Credential-Only-82!',
            'status' => 'active',
            'is_active' => true,
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    private function enablePatientAuth(): void
    {
        config([
            'nightingale.enabled' => true,
            'nightingale.features.token_exchange' => true,
        ]);
    }
}
