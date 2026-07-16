<?php

namespace Tests\Feature\Security;

use App\Contracts\AlertChannel;
use App\Http\Middleware\EnsureClinicalFailureOutputSafe;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\IntegrationControlPlaneService;
use App\Models\Cockpit\CockpitAlert;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Security\ClinicalPayloads\ClinicalContentLogTap;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalSafeLogManager;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Cockpit\AlertFanout;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ClinicalContentTaintFixtures;
use Tests\Support\TaintedClinicalIntegrationJob;
use Tests\TestCase;

final class ClinicalContentFailureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_failure_boundary_suppresses_every_tainted_transaction_family(): void
    {
        $middleware = app(EnsureClinicalFailureOutputSafe::class);
        $request = Request::create('/api/integrations/test-failure', 'POST');

        foreach (ClinicalContentTaintFixtures::all() as $family => $fixture) {
            $response = $middleware->handle(
                $request,
                static fn (): JsonResponse => response()->json([
                    'error' => ['code' => 'upstream_rejected', 'message' => $fixture],
                ], 422),
            );

            $this->assertSame(500, $response->getStatusCode(), "{$family} failure was not suppressed");
            $this->assertSame('clinical_failure_output_suppressed', $response->getData(true)['error']['code']);
            $this->assertNoCanary((string) $response->getContent());
            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('max-age=0', $cacheControl);
        }

        $safe = $middleware->handle(
            $request,
            static fn (): JsonResponse => response()->json([
                'error' => ['code' => 'projection_failed', 'message' => 'The bounded projection failed.'],
            ], 409),
        );
        $this->assertSame(409, $safe->getStatusCode());

        $validation = $middleware->handle(
            $request,
            static fn (): JsonResponse => response()->json([
                'message' => ClinicalContentTaintFixtures::all()['fhir_r4_xml'],
                'errors' => ['client_secret' => [ClinicalContentTaintFixtures::all()['private_key']]],
            ], 422),
        );
        $this->assertSame(422, $validation->getStatusCode());
        $this->assertSame(
            'The submitted value is invalid.',
            $validation->getData(true)['errors']['client_secret'][0],
        );
        $this->assertNoCanary((string) $validation->getContent());

        $conflict = $middleware->handle(
            $request,
            static fn (): JsonResponse => response()->json([
                'error' => ['code' => 'version_conflict', 'message' => 'The projection version changed.'],
                'current' => [
                    'data' => ['patient_label' => 'AUTHORIZED-RECOVERY-CONTEXT'],
                    'meta' => ['version' => 2],
                ],
            ], 409),
        );
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(2, $conflict->getData(true)['current']['meta']['version']);
    }

    public function test_global_failure_boundary_covers_json_errors_from_web_and_api_routes(): void
    {
        $fixture = ClinicalContentTaintFixtures::all()['fhir_r4'];
        foreach (['web', 'api'] as $group) {
            $uri = "/_clinical-content-boundary/{$group}";
            Route::middleware($group)->get($uri, static fn (): JsonResponse => response()->json([
                'error' => ['code' => 'upstream_rejected', 'message' => $fixture],
            ], 422));

            $response = $this->getJson($uri);
            $response->assertStatus(500)
                ->assertJsonPath('error.code', 'clinical_failure_output_suppressed')
                ->assertHeader('Pragma', 'no-cache');
            $this->assertNoCanary((string) $response->getContent());
        }
    }

    public function test_integration_jobs_require_declared_arguments_encrypt_at_rest_and_reject_content_before_enqueue(): void
    {
        config(['queue.default' => 'database']);

        Queue::connection('database')->push(
            new TaintedClinicalIntegrationJob('safe_diagnostic_code'),
            '',
            'integrations',
        );
        $queued = DB::table('jobs')->where('queue', 'integrations')->firstOrFail();
        $payload = json_decode((string) $queued->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($payload, 'zephyrus:clinical-content-safety.schema'));
        $this->assertTrue(data_get($payload, 'zephyrus:clinical-content-safety.encryptedCommand'));
        $this->assertIsString(data_get($payload, 'data.command'));
        $this->assertStringNotContainsString('safe_diagnostic_code', (string) $queued->payload);

        try {
            Queue::connection('database')->push(
                new TaintedClinicalIntegrationJob(ClinicalContentTaintFixtures::all()['hl7_v2']),
                '',
                'default',
            );
            $this->fail('A tainted job was accepted outside the dedicated integration queue.');
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame('clinical_payload_queue_payload_rejected', $exception->errorCode);
        }

        foreach (ClinicalContentTaintFixtures::all() as $fixture) {
            try {
                Queue::connection('database')->push(
                    new TaintedClinicalIntegrationJob($fixture),
                    '',
                    'integrations',
                );
                $this->fail('A tainted integration job was accepted by the queue boundary.');
            } catch (ClinicalPayloadException $exception) {
                $this->assertSame('clinical_payload_queue_payload_rejected', $exception->errorCode);
            }
        }

        $this->assertSame(1, DB::table('jobs')->where('queue', 'integrations')->count());
    }

    public function test_database_tripwires_reject_content_in_diagnostics_queue_failures_audit_and_evidence(): void
    {
        foreach (ClinicalContentTaintFixtures::all() as $fixture) {
            $databaseFixture = str_replace("\0", '', $fixture);
            $this->assertDatabaseWriteRejected(function () use ($databaseFixture): void {
                DB::table('raw.dead_letters')->insert([
                    'dead_letter_uuid' => (string) Str::uuid7(),
                    'failure_stage' => 'contract_test',
                    'reason_code' => 'upstream_rejected',
                    'message' => $databaseFixture,
                    'context' => '{}',
                    'status' => 'open',
                    'metadata' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->assertDatabaseWriteRejected(function () use ($databaseFixture): void {
                DB::table('failed_jobs')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'connection' => 'database',
                    'queue' => 'integrations',
                    'payload' => '{"safe":true}',
                    'exception' => $databaseFixture,
                    'failed_at' => now(),
                ]);
            });
        }

        $defaultQueueFixture = str_replace("\0", '', ClinicalContentTaintFixtures::all()['vendor_json']);
        $this->assertDatabaseWriteRejected(function () use ($defaultQueueFixture): void {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => $defaultQueueFixture,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        });
        $this->assertDatabaseWriteRejected(function () use ($defaultQueueFixture): void {
            DB::table('audit.user_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'occurred_at' => now(),
                'action' => 'security.content_boundary_test',
                'category' => 'security',
                'outcome' => 'failure',
                'source_surface' => 'system',
                'request_uuid' => (string) Str::uuid7(),
                'changes' => '{}',
                'metadata' => json_encode(['provider' => $defaultQueueFixture], JSON_THROW_ON_ERROR),
                'schema_version' => 1,
            ]);
        });

        try {
            app(UserAuditRecorder::class)->record(
                'security.content_boundary_test',
                'security',
                'failure',
                [
                    'source_surface' => 'system',
                    'metadata' => ['provider' => $defaultQueueFixture],
                ],
            );
            $this->fail('Tainted user-audit evidence was accepted.');
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame('clinical_content_audit_rejected', $exception->errorCode);
        }
        $this->assertDatabaseWriteRejected(function () use ($defaultQueueFixture): void {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid7(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{"safe":true}',
                'exception' => $defaultQueueFixture,
                'failed_at' => now(),
            ]);
        });

        $deadLetterId = DB::table('raw.dead_letters')->insertGetId([
            'dead_letter_uuid' => (string) Str::uuid7(),
            'failure_stage' => 'contract_test',
            'reason_code' => 'upstream_rejected',
            'message' => 'The upstream transaction failed.',
            'context' => '{}',
            'status' => 'open',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'dead_letter_id');

        $incidentSnapshot = app(IntegrationControlPlaneService::class)->snapshot();
        $incidentRecord = collect($incidentSnapshot['deadLetters'])->firstWhere('deadLetterId', $deadLetterId);
        $this->assertIsArray($incidentRecord);
        $this->assertSame('upstream_rejected', $incidentRecord['reasonCode']);
        $this->assertArrayNotHasKey('message', $incidentRecord);
        $this->assertArrayNotHasKey('context', $incidentRecord);
        $this->assertArrayNotHasKey('metadata', $incidentRecord);
        $this->assertNoCanary(json_encode($incidentSnapshot, JSON_THROW_ON_ERROR));

        app(IntegrationConfigurationAuditService::class)->record(
            null,
            'failed',
            'content_boundary_test',
            1,
            'opaque-1',
            [],
            ['errorCode' => 'safe_failure'],
            (string) Str::uuid7(),
        );
        $this->assertSame(1, DB::table('integration.configuration_audits')->count());

        foreach (ClinicalContentTaintFixtures::all() as $fixture) {
            try {
                app(IntegrationConfigurationAuditService::class)->record(
                    null,
                    'failed',
                    'content_boundary_test',
                    1,
                    'opaque-1',
                    [],
                    ['diagnostic' => $fixture],
                    (string) Str::uuid7(),
                );
                $this->fail('Tainted configuration evidence was accepted.');
            } catch (ClinicalPayloadException $exception) {
                $this->assertSame('clinical_content_audit_rejected', $exception->errorCode);
            }
        }
        $this->assertSame(1, DB::table('integration.configuration_audits')->count());
    }

    public function test_configured_log_and_alert_lanes_redact_or_suppress_content(): void
    {
        $directory = storage_path('framework/testing/clinical-content-log-'.Str::uuid7());
        $path = $directory.'/boundary.log';
        mkdir($directory, 0700, true);

        try {
            config(['logging.channels.content-boundary-test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                'replace_placeholders' => true,
                'tap' => [ClinicalContentLogTap::class],
            ]]);
            Log::forgetChannel('content-boundary-test');
            $logger = Log::channel('content-boundary-test');
            foreach (ClinicalContentTaintFixtures::all() as $fixture) {
                $logger->error($fixture, [
                    'payload' => $fixture,
                    'exception' => new RuntimeException($fixture),
                    'payload_object_id' => 91,
                ]);
            }

            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString(ClinicalContentGuard::REDACTED, $contents);
            $this->assertStringContainsString('payload_object_id', $contents);
            $this->assertNoCanary($contents);
        } finally {
            Log::forgetChannel('content-boundary-test');
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $spy = new class implements AlertChannel
        {
            public int $sent = 0;

            public function send(CockpitAlert $alert): int
            {
                $this->sent++;

                return 1;
            }
        };
        $fanout = new AlertFanout([$spy], app(ClinicalContentGuard::class));
        foreach (ClinicalContentTaintFixtures::all() as $fixture) {
            $fanout->alertOpened(new CockpitAlert([
                'facility_key' => 'SAFE-FACILITY',
                'key' => 'integration.failure',
                'status' => 'crit',
                'text' => $fixture,
            ]));
        }
        $this->assertSame(0, $spy->sent);
    }

    public function test_emergency_log_fallback_redacts_content_when_channel_resolution_fails(): void
    {
        $directory = storage_path('framework/testing/clinical-content-emergency-log-'.Str::uuid7());
        $path = $directory.'/emergency.log';
        mkdir($directory, 0700, true);

        try {
            $this->assertInstanceOf(ClinicalSafeLogManager::class, Log::getFacadeRoot());
            config([
                'logging.channels.content-boundary-broken' => ['driver' => 'not-a-real-driver'],
                'logging.channels.emergency.path' => $path,
            ]);
            Log::forgetChannel('content-boundary-broken');

            Log::channel('content-boundary-broken')->error(ClinicalContentTaintFixtures::all()['private_key']);

            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString(ClinicalContentGuard::REDACTED, $contents);
            $this->assertNoCanary($contents);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $contents);
        } finally {
            Log::forgetChannel('content-boundary-broken');
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_release_evidence_redacts_command_and_failure_output_before_persistence(): void
    {
        $directory = storage_path('framework/testing/release-evidence-'.Str::uuid7());
        $fixture = json_encode(
            json_decode(ClinicalContentTaintFixtures::all()['fhir_r4'], true, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $php = 'fwrite(STDOUT, '.var_export($fixture, true).'); exit(23);';
        $process = new Process(
            ['bash', 'scripts/capture-release-evidence.sh', 'content-boundary-test', PHP_BINARY, '-r', $php],
            base_path(),
            ['RELEASE_EVIDENCE_DIR' => $directory],
        );

        try {
            $process->run();
            $this->assertSame(23, $process->getExitCode());
            $logs = glob($directory.'/*.log') ?: [];
            $manifests = glob($directory.'/*.json') ?: [];
            $this->assertCount(1, $logs);
            $this->assertCount(1, $manifests);

            $persisted = (string) file_get_contents($logs[0]).(string) file_get_contents($manifests[0]);
            $this->assertStringContainsString(ClinicalContentGuard::REDACTED, $persisted);
            $this->assertNoCanary($persisted);
            $this->assertNoCanary($process->getOutput().$process->getErrorOutput());
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    private function assertDatabaseWriteRejected(callable $write): void
    {
        try {
            DB::transaction(static function () use ($write): void {
                $write();
            });
            $this->fail('The database clinical-content tripwire accepted a tainted row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'clinical content is prohibited in diagnostic and evidence authorities',
                $exception->getMessage(),
            );
        }
    }

    private function assertNoCanary(string $value): void
    {
        foreach (ClinicalContentTaintFixtures::canaries() as $canary) {
            $this->assertStringNotContainsString($canary, $value);
        }
    }
}
