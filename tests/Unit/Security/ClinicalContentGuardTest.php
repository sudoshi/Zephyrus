<?php

namespace Tests\Unit\Security;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Jobs\Middleware\FailClinicalJobSafely;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Security\ClinicalPayloads\ClinicalContentLogProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\Support\ClinicalContentTaintFixtures;
use Tests\TestCase;

final class ClinicalContentGuardTest extends TestCase
{
    public function test_detects_every_supported_transaction_family_and_secret_canary(): void
    {
        $guard = new ClinicalContentGuard;

        foreach (ClinicalContentTaintFixtures::all() as $family => $fixture) {
            $this->assertTrue($guard->contains($fixture), "{$family} content was not detected");
            $this->assertSame(ClinicalContentGuard::REDACTED.PHP_EOL, $guard->redactString($fixture));
        }

        $prettyFhir = json_encode(
            json_decode(ClinicalContentTaintFixtures::all()['fhir_r4'], true, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $this->assertStringContainsString(PHP_EOL, $prettyFhir);
        $this->assertTrue($guard->contains($prettyFhir));
        $this->assertSame(ClinicalContentGuard::REDACTED.PHP_EOL, $guard->redactString($prettyFhir));

        $this->assertFalse($guard->contains([
            'payload_object_id' => 41,
            'payload_kind' => 'canonical_event',
            'resource_type' => 'Encounter',
            'error_code' => 'projection_failed',
            'counts' => ['received' => 10, 'failed' => 1],
        ]));
    }

    public function test_log_processor_removes_content_and_exception_arguments_but_retains_safe_frames(): void
    {
        $fixture = ClinicalContentTaintFixtures::all()['hl7_v2'];
        $processor = new ClinicalContentLogProcessor(new ClinicalContentGuard);
        $record = new LogRecord(
            new DateTimeImmutable,
            'testing',
            Level::Error,
            $fixture,
            [
                'payload' => $fixture,
                'exception' => new RuntimeException($fixture),
                'payload_object_id' => 51,
            ],
        );

        $safe = $processor($record);
        $encoded = json_encode($safe->toArray(), JSON_THROW_ON_ERROR);
        $this->assertSame(ClinicalContentGuard::REDACTED, $safe->message);
        $this->assertSame(ClinicalContentGuard::REDACTED, $safe->context['payload']);
        $this->assertSame(51, $safe->context['payload_object_id']);
        $this->assertSame(RuntimeException::class, $safe->context['exception']['class']);
        $this->assertSame(ClinicalContentGuard::REDACTED, $safe->context['exception']['message']);
        $this->assertStringNotContainsString('ZPHI-HL7', $encoded);
        $this->assertArrayHasKey('frames', $safe->context['exception']);
    }

    public function test_queue_middleware_rethrows_only_a_stable_code_without_previous_content(): void
    {
        $fixture = ClinicalContentTaintFixtures::all()['fhir_r4'];

        try {
            (new FailClinicalJobSafely)->handle(new \stdClass, static function () use ($fixture): never {
                throw new RuntimeException($fixture);
            });
            $this->fail('The failure middleware must replace the unsafe exception.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('integration_job_failed', $exception->errorCode);
            $this->assertSame('integration_job_failed', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('ZPHI-FHIR', (string) $exception);
        }
    }
}
