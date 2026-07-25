<?php

namespace Tests\Feature\Patient;

use stdClass;
use Tests\TestCase;

class PatientSharedDtoFixtureTest extends TestCase
{
    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures/patient';

    private const IOS_DECODE_TEST = 'hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIModelTests.swift';

    private const ANDROID_DECODE_TEST = 'hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientProjectionFixtureDecodeTest.kt';

    private const PROVENANCE = 'docs/hummingbird/api-contract/fixtures/patient/fixture-provenance.v1.json';

    /** @var array<string, string> */
    private const FIXTURES = [
        'patient-today.json' => 'today',
        'patient-pathway.json' => 'pathway',
        'patient-pathway-events.json' => 'pathway_events',
        'patient-discharge-readiness.json' => 'discharge_readiness',
        'patient-rounds-summary.json' => 'rounds_summary',
        'patient-care-team.json' => 'care_team',
    ];

    public function test_patient_projection_fixtures_are_valid_patient_api_envelopes(): void
    {
        foreach (self::FIXTURES as $filename => $kind) {
            $fixture = $this->fixture($filename);

            $this->assertInstanceOf(stdClass::class, $fixture);
            $this->assertInstanceOf(stdClass::class, $fixture->data ?? null, "{$filename} data must be an object.");
            $this->assertInstanceOf(stdClass::class, $fixture->meta ?? null, "{$filename} meta must be an object.");
            $this->assertInstanceOf(stdClass::class, $fixture->links ?? null, "{$filename} links must remain an object, including when empty.");
            $this->assertSame($kind, $fixture->data->kind ?? null, "{$filename} has the wrong projection kind.");
            $this->assertIsString($fixture->data->projection_uuid ?? null, "{$filename} must contain an opaque projection handle.");
            $this->assertIsString($fixture->data->encounter_uuid ?? null, "{$filename} must contain an opaque encounter handle.");
            $this->assertInstanceOf(stdClass::class, $fixture->data->content ?? null, "{$filename} content must be structured.");
            $this->assertInstanceOf(stdClass::class, $fixture->data->uncertainty ?? null, "{$filename} uncertainty must be structured.");
            $this->assertInstanceOf(stdClass::class, $fixture->data->provenance ?? null, "{$filename} provenance must be structured.");
            $this->assertSame(false, $fixture->meta->stale ?? null, "{$filename} must represent a current projection.");
            $this->assertSame(1, $fixture->meta->version ?? null, "{$filename} must retain its release version.");
            $this->assertSame('current', $fixture->meta->source_freshness->status ?? null, "{$filename} freshness drifted.");
            $this->assertSame('patient-state-vocabulary.v1-draft', $fixture->meta->state_vocabulary_version ?? null, "{$filename} vocabulary version drifted.");
        }
    }

    public function test_patient_projection_fixtures_exclude_identity_and_raw_source_material(): void
    {
        $fixtureText = collect(array_keys(self::FIXTURES))
            ->map(fn (string $fixture): string => file_get_contents(base_path(self::FIXTURE_DIR.'/'.$fixture)))
            ->implode("\n");

        foreach ([
            '"access_grant_id"',
            '"cursor_digest"',
            '"email"',
            '"phone_e164"',
            '"principal_uuid"',
            '"source_encounter_ref_digest"',
            '"source_system_key"',
            '"trace_digest"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $fixtureText, "Patient fixture leaks {$forbidden}.");
        }

        $this->assertStringContainsString('"encounter_uuid"', $fixtureText);
        $this->assertStringContainsString('"projection_uuid"', $fixtureText);
        $this->assertStringContainsString('"call_button_for_urgent_help"', $fixtureText);
    }

    public function test_patient_projection_fixtures_have_real_bff_and_native_decoder_provenance(): void
    {
        $provenance = json_decode(
            file_get_contents(base_path(self::PROVENANCE)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $provenance['schema_version'] ?? null);
        $this->assertStringContainsString(
            'HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1',
            $provenance['regeneration']['command'] ?? '',
        );

        $declared = collect($provenance['fixtures'] ?? [])
            ->mapWithKeys(fn (array $fixture): array => [$fixture['filename'] ?? '' => $fixture])
            ->all();

        $this->assertSame(array_keys(self::FIXTURES), array_keys($declared));

        $ios = file_get_contents(base_path(self::IOS_DECODE_TEST));
        $android = file_get_contents(base_path(self::ANDROID_DECODE_TEST));
        foreach ($declared as $filename => $fixture) {
            $this->assertSame('test_only_synthetic_projection_bff', $fixture['source'] ?? null, "{$filename} must be captured from the synthetic test-only patient BFF.");
            $this->assertIsString($fixture['endpoint'] ?? null, "{$filename} must retain its source endpoint.");
            $this->assertSame('PatientProjectionFixtureRegenerationTest', $fixture['generator'] ?? null, "{$filename} must name its regeneration test.");
            $this->assertStringContainsString($filename, $ios, "iOS decoder does not cover {$filename}.");
            $this->assertStringContainsString($filename, $android, "Android decoder does not cover {$filename}.");
        }
    }

    private function fixture(string $filename): stdClass
    {
        return json_decode(
            file_get_contents(base_path(self::FIXTURE_DIR.'/'.$filename)),
            false,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
