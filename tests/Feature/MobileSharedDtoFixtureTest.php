<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileSharedDtoFixtureTest extends TestCase
{
    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures';

    private const IOS_DECODE_SCRIPT = 'hummingbird/iosApp/scripts/decode-shared-fixtures.swift';

    private const ANDROID_DECODE_TEST = 'hummingbird/androidApp/app/src/test/java/net/acumenus/hummingbird/data/SharedDtoFixtureDecodeTest.kt';

    private const FIXTURES = [
        'mobile-altitude-home.json',
        'mobile-for-you.json',
        'mobile-activity-feed.json',
        'mobile-patient-operational-context.json',
        'mobile-flow-window.json',
        'mobile-flow-window-evs.json',
        'mobile-flow-floors.json',
        'mobile-transport-queue.json',
    ];

    public function test_shared_dto_fixtures_are_valid_uniform_envelopes(): void
    {
        foreach (self::FIXTURES as $fixture) {
            $json = $this->fixture($fixture);

            $this->assertArrayHasKey('data', $json, "{$fixture} must include data.");
            $this->assertArrayHasKey('meta', $json, "{$fixture} must include meta.");
            $this->assertArrayHasKey('links', $json, "{$fixture} must include links.");
            $this->assertArrayHasKey('as_of', $json['meta'], "{$fixture} must include meta.as_of.");
            $this->assertArrayHasKey('stale', $json['meta'], "{$fixture} must include meta.stale.");
            $this->assertArrayHasKey('version', $json['meta'], "{$fixture} must include meta.version.");
            $this->assertArrayHasKey('web', $json['links'], "{$fixture} must include links.web.");
            $this->assertNotSame('', $json['links']['web'], "{$fixture} links.web must be non-empty.");
        }
    }

    public function test_shared_dto_fixtures_are_phi_safe_and_use_visual_status(): void
    {
        $fixtureText = collect(self::FIXTURES)
            ->map(fn (string $fixture): string => file_get_contents(base_path(self::FIXTURE_DIR.'/'.$fixture)))
            ->implode("\n");

        $this->assertStringNotContainsString('"patient_ref"', $fixtureText);
        $this->assertStringNotContainsString('"encounter_ref": "', $fixtureText);
        $this->assertStringContainsString('"patient_context_ref"', $fixtureText);
        $this->assertStringContainsString('"visual_status"', $fixtureText);

        foreach ($this->collectValuesByKey($fixtureText, 'visual_status') as $status) {
            $this->assertContains($status, ['success', 'warning', 'critical', 'info']);
        }
    }

    public function test_first_wave_fixtures_are_referenced_by_ios_and_android_decode_harnesses(): void
    {
        $ios = file_get_contents(base_path(self::IOS_DECODE_SCRIPT));
        $android = file_get_contents(base_path(self::ANDROID_DECODE_TEST));

        foreach (self::FIXTURES as $fixture) {
            $this->assertStringContainsString($fixture, $ios, "iOS decode harness does not cover {$fixture}.");
            $this->assertStringContainsString($fixture, $android, "Android decode test does not cover {$fixture}.");
        }

        $this->assertStringContainsString('Envelope<MobileAltitudeHome>', $ios);
        $this->assertStringContainsString('Envelope<[ForYouItem]>', $ios);
        $this->assertStringContainsString('Envelope<[ActivityEvent]>', $ios);
        $this->assertStringContainsString('Envelope<PatientOperationalContext>', $ios);
        $this->assertStringContainsString('Envelope<FlowWindowData>', $ios);
        $this->assertStringContainsString('Envelope<FlowFloorsDocument>', $ios);
        $this->assertStringContainsString('Envelope<TransportQueue>', $ios);
        $this->assertStringContainsString('parseAltitudeHome', $android);
        $this->assertStringContainsString('parseForYouItem', $android);
        $this->assertStringContainsString('parseActivityEvent', $android);
        $this->assertStringContainsString('parsePatientContext', $android);
        $this->assertStringContainsString('parseFlowWindow', $android);
        $this->assertStringContainsString('parseFlowFloors', $android);
        $this->assertStringContainsString('parseTransportQueue', $android);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $fixture): array
    {
        return json_decode(file_get_contents(base_path(self::FIXTURE_DIR.'/'.$fixture)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, string>
     */
    private function collectValuesByKey(string $json, string $key): array
    {
        preg_match_all('/"'.preg_quote($key, '/').'":\s*"([^"]+)"/', $json, $matches);

        return $matches[1] ?? [];
    }
}
