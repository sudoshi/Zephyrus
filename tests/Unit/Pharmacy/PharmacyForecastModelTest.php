<?php

namespace Tests\Unit\Pharmacy;

use App\Services\Pharmacy\Forecast\PharmacyForecastBacktester;
use App\Services\Pharmacy\Forecast\PharmacyForecastModelArtifact;
use App\Services\Pharmacy\Forecast\StockoutFeatureExtractor;
use App\Services\Pharmacy\Forecast\StockoutFeatureSchema;
use App\Services\Pharmacy\Forecast\StockoutObservation;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Database-free acceptance for both deterministic P4-3 forecast targets. */
class PharmacyForecastModelTest extends TestCase
{
    private function backtester(): PharmacyForecastBacktester
    {
        return new PharmacyForecastBacktester(new StockoutFeatureExtractor);
    }

    public function test_feature_schema_is_station_medication_operational_only(): void
    {
        $this->assertSame([
            'inventory_ratio', 'vend_velocity_norm', 'refill_velocity_norm', 'refill_gap_norm',
            'shortage_flag', 'station_downtime', 'hour_sin', 'hour_cos', 'is_weekend', 'inventory_pressure',
        ], StockoutFeatureSchema::FEATURES);

        foreach (StockoutFeatureSchema::FEATURES as $feature) {
            foreach (StockoutFeatureSchema::FORBIDDEN_SUBSTRINGS as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $feature);
            }
        }
    }

    public function test_both_targets_use_strictly_later_time_evaluation_windows(): void
    {
        $artifact = $this->backtester()->buildArtifact();
        foreach (['queue', 'stockout'] as $target) {
            $window = $artifact[$target]['trainingWindow'];
            $this->assertLessThan(
                CarbonImmutable::parse($window['evaluateFrom'])->getTimestamp(),
                CarbonImmutable::parse($window['trainTo'])->getTimestamp(),
                "{$target} training must end strictly before evaluation begins.",
            );
            $this->assertSame($window['cohortSize'], $window['trainCount'] + $window['evaluateCount']);
            $this->assertGreaterThan(0, $window['trainCount']);
            $this->assertGreaterThan(0, $window['evaluateCount']);
        }
    }

    public function test_queue_reports_mae_rmse_wape_and_beats_both_declared_baselines(): void
    {
        $evaluation = $this->backtester()->buildArtifact()['queue']['evaluation'];

        foreach (['mae', 'rmse', 'wape'] as $metric) {
            $this->assertArrayHasKey($metric, $evaluation);
        }
        $this->assertSame(1.0, $evaluation['coverage']['fraction']);
        $this->assertTrue($evaluation['beatsBaselines']);
        foreach (['seasonalHourOfWeek', 'lastValue'] as $baseline) {
            $this->assertLessThan($evaluation['baselines'][$baseline]['mae'], $evaluation['mae']);
            $this->assertLessThan($evaluation['baselines'][$baseline]['rmse'], $evaluation['rmse']);
        }
    }

    public function test_stockout_reports_calibration_discrimination_coverage_and_beats_base_rate(): void
    {
        $evaluation = $this->backtester()->buildArtifact()['stockout']['evaluation'];

        $this->assertArrayHasKey('calibrationError', $evaluation);
        $this->assertArrayHasKey('discriminationAuc', $evaluation);
        $this->assertArrayHasKey('brierScore', $evaluation);
        $this->assertSame(1.0, $evaluation['coverage']['fraction']);
        $this->assertTrue($evaluation['beatsBaseline']);
        $this->assertGreaterThan($evaluation['naiveBaseline']['discriminationAuc'], $evaluation['discriminationAuc']);
        $this->assertLessThan($evaluation['naiveBaseline']['brierScore'], $evaluation['brierScore']);
    }

    public function test_artifact_is_deterministic_versioned_and_explicitly_synthetic(): void
    {
        $first = $this->backtester()->buildArtifact();
        $second = $this->backtester()->buildArtifact();
        $committed = PharmacyForecastModelArtifact::load();

        $this->assertSame($first, $second);
        $this->assertSame(PharmacyForecastBacktester::MODEL_VERSION, $committed->version());
        $this->assertTrue($committed->provenance()['synthetic']);
        $this->assertStringContainsString('planning aid only', $committed->provenance()['syntheticLabel']);
        $this->assertTrue($committed->queue()['evaluation']['beatsBaselines']);
        $this->assertTrue($committed->stockout()['evaluation']['beatsBaseline']);
        $this->assertSame(StockoutFeatureSchema::FEATURES, $committed->stockout()['featureSchema']);
        $this->assertNotEmpty($committed->provenance()['queueTrainingWindow']);
        $this->assertNotEmpty($committed->provenance()['stockoutTrainingWindow']);
    }

    public function test_inventory_cutoff_contract_distinguishes_current_stale_missing_and_future(): void
    {
        $extractor = new StockoutFeatureExtractor;
        $now = CarbonImmutable::parse('2026-07-15T12:00:00Z');
        $observation = fn (?float $onHand, ?float $par, ?CarbonImmutable $capturedAt): StockoutObservation => new StockoutObservation(
            stationId: 1,
            stationKey: 'demo:station:1',
            localCode: 'RX-DEMO-001',
            predictedAt: $now->toDateTimeImmutable(),
            onHand: $onHand,
            parLevel: $par,
            inventoryCapturedAt: $capturedAt?->toDateTimeImmutable(),
            vendPerHour: 1.5,
            refillUnitsPerHour: 0.5,
            minutesSinceRefill: 120.0,
            shortageFlag: false,
            stationDown: false,
        );

        $current = $extractor->extract($observation(3.0, 12.0, $now->subMinutes(30)), $now);
        $this->assertSame('available', $current['availability']);
        $this->assertSame(StockoutFeatureSchema::FEATURES, array_keys($current['features']));

        $this->assertSame('low_confidence', $extractor->extract($observation(3.0, 12.0, $now->subMinutes(121)), $now)['availability']);

        $expired = $extractor->extract($observation(3.0, 12.0, $now->subMinutes(721)), $now);
        $this->assertSame('unavailable', $expired['availability']);
        $this->assertContains('inventory_snapshot_expired', $expired['missing']);

        $missing = $extractor->extract($observation(null, 12.0, $now), $now);
        $this->assertSame('unavailable', $missing['availability']);
        $this->assertContains('on_hand', $missing['missing']);

        $future = $extractor->extract($observation(3.0, 12.0, $now->addMinute()), $now);
        $this->assertSame('unavailable', $future['availability']);
        $this->assertContains('inventory_cutoff_in_future', $future['missing']);

        $invalid = $extractor->extract($observation(-1.0, 12.0, $now), $now);
        $this->assertSame('unavailable', $invalid['availability']);
        $this->assertContains('on_hand_invalid', $invalid['missing']);
    }

    public function test_calibration_map_is_bounded_and_monotone(): void
    {
        $model = PharmacyForecastModelArtifact::load()->stockoutModel();
        $previous = -1.0;
        foreach ([0.0, 0.1, 0.25, 0.5, 0.75, 0.9, 1.0] as $raw) {
            $calibrated = $model->applyCalibration($raw);
            $this->assertGreaterThanOrEqual(0.0, $calibrated);
            $this->assertLessThanOrEqual(1.0, $calibrated);
            $this->assertGreaterThanOrEqual($previous - 1e-9, $calibrated);
            $previous = $calibrated;
        }
    }
}
