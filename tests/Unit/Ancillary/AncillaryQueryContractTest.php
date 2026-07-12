<?php

namespace Tests\Unit\Ancillary;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use App\Services\Ancillary\AncillaryStatistics;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AncillaryQueryContractTest extends TestCase
{
    public function test_distribution_uses_continuous_median_and_p90_and_empty_is_unavailable(): void
    {
        $statistics = new AncillaryStatistics;

        $this->assertSame(['count' => 4, 'median' => 25.0, 'p90' => 44.0], $statistics->distribution([10, 20, 30, 50]));
        $this->assertSame(['count' => 0, 'median' => null, 'p90' => null], $statistics->distribution([]));
        $this->assertSame(['count' => 2, 'median' => 15.0, 'p90' => 19.0], $statistics->distribution([10, -1, '20', 'bad']));
        $this->assertNull($statistics->percentile([1, 2], 1.1));
    }

    #[DataProvider('intervals')]
    public function test_interval_minutes_rejects_invalid_or_negative_intervals(
        DateTimeImmutable|string|null $start,
        DateTimeImmutable|string|null $stop,
        string $timezone,
        ?float $expected,
    ): void {
        $this->assertSame($expected, (new AncillaryStatistics)->intervalMinutes($start, $stop, $timezone));
    }

    /** @return iterable<string, array{DateTimeImmutable|string|null,DateTimeImmutable|string|null,string,?float}> */
    public static function intervals(): iterable
    {
        yield 'UTC interval' => ['2026-07-11T12:00:00Z', '2026-07-11T13:30:00Z', 'UTC', 90.0];
        yield 'same instant across offsets' => ['2026-07-11T12:00:00-04:00', '2026-07-11T13:00:00-03:00', 'UTC', 0.0];
        yield 'naive timestamps use supplied timezone across DST' => ['2026-03-08 01:30:00', '2026-03-08 03:30:00', 'America/New_York', 60.0];
        yield 'negative interval' => ['2026-07-11T13:00:00Z', '2026-07-11T12:00:00Z', 'UTC', null];
        yield 'invalid start' => ['not-a-date', '2026-07-11T12:00:00Z', 'UTC', null];
        yield 'missing stop' => ['2026-07-11T12:00:00Z', null, 'UTC', null];
    }

    public function test_serializers_emit_explicit_freshness_and_sla_definition_contracts(): void
    {
        $serializer = new AncillaryContractSerializer;
        $freshness = new FreshnessEnvelope(
            status: 'stale',
            asOf: new DateTimeImmutable('2026-07-11T14:00:00Z'),
            sourceCutoffAt: new DateTimeImmutable('2026-07-11T13:00:00Z'),
            lagMinutes: 60,
            sourceLabel: 'RIS',
            explanation: 'Source heartbeat delayed.',
        );

        $this->assertSame('2026-07-11T14:00:00+00:00', $serializer->freshness($freshness)['asOf']);

        $definition = new AncillarySlaDefinition([
            'definition_uuid' => '22222222-2222-4222-8222-222222222222',
            'department' => 'rad',
            'metric_key' => 'rad.stat_order_final',
            'label' => 'STAT order to final',
            'start_milestone_code' => 'RAD_ORDERED',
            'stop_milestone_code' => 'RAD_FINAL',
            'priority' => 'stat',
            'patient_class' => null,
            'statistic' => 'item_clock',
            'warning_minutes' => 45,
            'breach_minutes' => 60,
            'target_value' => null,
            'direction' => 'lower_is_better',
            'unit' => 'minutes',
            'effective_from' => CarbonImmutable::parse('2026-07-11T00:00:00Z'),
            'effective_to' => null,
            'version' => 1,
            'active' => true,
            'definition_text' => 'Selected order assertion to selected final assertion.',
            'source_reference_id' => 'governance:rad-stat-v1',
        ]);

        $contract = $serializer->slaDefinition($definition);
        $this->assertSame('rad.stat_order_final', $contract['metricKey']);
        $this->assertSame(45, $contract['warningMinutes']);
        $this->assertSame('2026-07-11T00:00:00+00:00', $contract['effectiveFrom']);
        $this->assertTrue($contract['active']);
    }
}
