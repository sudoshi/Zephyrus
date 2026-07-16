<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiIngressContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_receives_a_trusted_request_identifier(): void
    {
        $generated = $this->withHeader('X-Request-ID', 'not-a-uuid')
            ->getJson('/api/health')
            ->assertOk()
            ->headers->get('X-Request-ID');

        $this->assertIsString($generated);
        $this->assertTrue(Str::isUuid($generated));
        $this->assertNotSame('not-a-uuid', $generated);

        $trusted = (string) Str::uuid();
        $this->withHeader('X-Request-ID', strtoupper($trusted))
            ->getJson('/api/health')
            ->assertHeader('X-Request-ID', $trusted);

        $correlation = (string) Str::uuid();
        $this->withoutHeader('X-Request-ID')
            ->withHeader('X-Correlation-ID', $correlation)
            ->getJson('/api/health')
            ->assertHeader('X-Request-ID', $correlation);
    }

    public function test_api_rejects_untyped_and_oversized_request_bodies(): void
    {
        $untyped = $this->call(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: 'username=test&password=test',
        );
        $untyped->assertStatus(415)
            ->assertJsonPath('error.code', 'unsupported_media_type')
            ->assertJsonPath('request_id', $untyped->headers->get('X-Request-ID'));

        config()->set('ingress.api_max_bytes', 1024);
        $oversized = $this->call(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['username' => str_repeat('x', 1100)], JSON_THROW_ON_ERROR),
        );
        $oversized->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_json_suffix_media_types_are_accepted(): void
    {
        $response = $this->call(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'application/fhir+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['username' => 'missing', 'password' => 'invalid'], JSON_THROW_ON_ERROR),
        );

        $response->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_hl7_ingress_accepts_the_standard_raw_media_types(): void
    {
        $user = User::factory()->create(['role' => 'integration']);
        $token = $user->createToken(
            'integration:patient-flow',
            ['integration:patient-flow:ingest'],
        )->plainTextToken;

        foreach (['application/hl7-v2', 'application/edi-hl7', 'text/plain; charset=utf-8'] as $contentType) {
            $response = $this->call(
                'POST',
                '/api/integrations/v1/patient-flow/hl7v2',
                server: [
                    'CONTENT_TYPE' => $contentType,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ],
                content: 'MSH|^~\\&|SOURCE|HOSP|ZEPHYRUS|HOSP|202607131200||ADT^A01|MSG-1|P|2.5',
            );

            $this->assertSame(422, $response->status());
            $response->assertJsonPath('error.code', 'integration_source_required');
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_public_health_uses_its_named_rate_limit(): void
    {
        config()->set('ingress.rate_limits.public_health_per_minute', 2);
        RateLimiter::clear('health:127.0.0.1');

        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health')->assertStatus(429);

        RateLimiter::clear('health:127.0.0.1');
    }
}
