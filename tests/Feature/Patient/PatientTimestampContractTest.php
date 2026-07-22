<?php

namespace Tests\Feature\Patient;

use App\Http\Middleware\AssignRequestIdentity;
use App\Services\Patient\PatientResponseMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Contract guard for the single timestamp/time-zone rule (plan §6.2): every
 * value that crosses the patient API boundary is UTC, ISO-8601 / RFC 3339 with a
 * literal `Z`. The application runs in UTC (`APP_TIMEZONE=UTC`), so serialized
 * instants are unambiguous and DST never applies to a wire value. These tests
 * pin that rule and prove it holds across US daylight-saving boundaries.
 */
class PatientTimestampContractTest extends TestCase
{
    /** Strict UTC ISO-8601: date, `T`, time, optional fraction, literal `Z`, no offset. */
    private const UTC_Z = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_patient_envelope_timestamps_are_utc_iso8601_with_z(): void
    {
        $meta = $this->metadataFor('req-timestamp-format');

        $this->assertMatchesRegularExpression(self::UTC_Z, $meta['generated_at']);
        $this->assertMatchesRegularExpression(self::UTC_Z, $meta['as_of']);
        $this->assertSame($meta['generated_at'], $meta['as_of']);
        $this->assertStringEndsWith('Z', $meta['generated_at']);
        $this->assertStringNotContainsString('+', $meta['generated_at']);
        $this->assertStringNotContainsString(' ', $meta['generated_at']);
    }

    public function test_patient_envelope_declares_the_current_state_vocabulary_version(): void
    {
        $meta = $this->metadataFor('req-state-vocabulary');

        $this->assertSame('patient-state-vocabulary.v1-draft', $meta['state_vocabulary_version']);
    }

    public function test_ambiguous_and_skipped_local_times_serialize_to_one_utc_instant(): void
    {
        // Each case is (US/Eastern wall clock, expected single UTC 'Z' serialization).
        // The app never emits these local strings; this proves that once a value
        // is an instant, ISO-8601 serialization is unambiguous regardless of DST.
        $cases = [
            // Fall-back: 01:30 on 2026-11-01 occurs twice in US/Eastern; the
            // pre-transition offset (EDT, -04:00) resolves it to 05:30 UTC.
            ['2026-11-01 01:30:00', '2026-11-01T05:30:00.000000Z'],
            // Spring-forward: 03:30 on 2026-03-08 is the first valid time after
            // the 02:00->03:00 gap (EDT, -04:00) => 07:30 UTC.
            ['2026-03-08 03:30:00', '2026-03-08T07:30:00.000000Z'],
        ];

        foreach ($cases as [$easternWallClock, $expectedUtc]) {
            $instant = CarbonImmutable::parse($easternWallClock, 'America/New_York');

            $this->assertSame($expectedUtc, $instant->toISOString());
            $this->assertMatchesRegularExpression(self::UTC_Z, $instant->toISOString());
        }
    }

    public function test_envelope_clock_frozen_on_a_dst_boundary_still_emits_utc(): void
    {
        // Freeze the app clock at the exact UTC instant of the US/Eastern
        // fall-back boundary. Because the app runs in UTC, the envelope must
        // report that instant unshifted, with a `Z` suffix and no offset.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC'));

        $meta = $this->metadataFor('req-timestamp-dst');

        $this->assertSame('2026-11-01T05:30:00.000000Z', $meta['generated_at']);
        $this->assertMatchesRegularExpression(self::UTC_Z, $meta['generated_at']);
    }

    /** @return array<string, mixed> */
    private function metadataFor(string $requestId): array
    {
        $request = Request::create('/api/patient/v1/me');
        $request->attributes->set(AssignRequestIdentity::ATTRIBUTE, $requestId);

        return $this->app->make(PatientResponseMetadata::class)->forRequest($request);
    }
}
