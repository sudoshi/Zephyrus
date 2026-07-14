<?php

namespace Tests\Unit\Lab;

use App\Services\Lab\AmReadiness\AmReadinessBacktester;
use App\Services\Lab\AmReadiness\AmReadinessFeatureExtractor;
use App\Services\Lab\AmReadiness\AmReadinessFeatureSchema;
use App\Services\Lab\AmReadiness\AmReadinessModelArtifact;
use App\Services\Lab\AmReadiness\AmReadinessObservation;
use App\Services\Lab\AmReadiness\CalibratedLogisticModel;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Unit acceptance for the P4-2 Laboratory AM-readiness planning model. These
 * tests need no database — they exercise the deterministic backtest pipeline,
 * the committed artifact (loaded via the booted config path), the leakage guard,
 * and the ethics/feature-schema guard directly.
 */
class AmReadinessModelTest extends TestCase
{
    private function backtester(): AmReadinessBacktester
    {
        return new AmReadinessBacktester(new AmReadinessFeatureExtractor);
    }

    public function test_feature_schema_is_operational_only_with_no_leakage_or_protected_attributes(): void
    {
        foreach (AmReadinessFeatureSchema::FEATURES as $feature) {
            foreach (AmReadinessFeatureSchema::FORBIDDEN_SUBSTRINGS as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $feature,
                    "Feature '{$feature}' contains forbidden token '{$forbidden}' — no outcome-encoding, clinical, or protected attribute may enter the schema without review.",
                );
            }
        }

        // The only admitted patient descriptor is operational patient_class.
        $patientFeatures = array_filter(
            AmReadinessFeatureSchema::FEATURES,
            static fn (string $feature): bool => str_starts_with($feature, 'patient_'),
        );
        $this->assertSame(['patient_emergency', 'patient_inpatient'], array_values($patientFeatures));
    }

    public function test_backtest_uses_a_time_based_split_and_no_future_information(): void
    {
        $artifact = $this->backtester()->buildArtifact();
        $window = $artifact['trainingWindow'];

        // The training window must end strictly before the evaluation window begins.
        $this->assertLessThanOrEqual(
            CarbonImmutable::parse($window['evaluateFrom'])->getTimestamp(),
            CarbonImmutable::parse($window['trainTo'])->getTimestamp(),
        );
        $this->assertSame($window['trainCount'] + $window['evaluateCount'], $window['cohortSize']);
        $this->assertGreaterThan(0, $window['trainCount']);
        $this->assertGreaterThan(0, $window['evaluateCount']);
    }

    public function test_target_documents_the_rounds_cutoff_policy(): void
    {
        $target = $this->backtester()->buildArtifact()['target'];

        $this->assertArrayHasKey('roundsCutoffLabel', $target);
        $this->assertNotSame('', $target['roundsCutoffLabel']);
        $this->assertStringContainsString('LAB_VERIFIED', $target['definition']);
        $this->assertStringContainsString('policy', strtolower($target['cutoffRule']));
    }

    public function test_feature_extraction_at_prediction_time_cannot_see_the_outcome(): void
    {
        $extractor = new AmReadinessFeatureExtractor;
        $now = CarbonImmutable::parse('2026-07-13T06:00:00Z');

        $observation = new AmReadinessObservation(
            orderId: 1,
            orderUuid: '00000000-0000-4000-8000-000000000001',
            stage: 'LAB_RECEIVED',
            testFamily: 'metabolic_panel',
            priority: 'stat',
            patientClass: 'inpatient',
            isAmDraw: true,
            queueDepth: 6,
            analyzerDowntime: false,
            roundsCutoffAt: $now->addMinutes(90)->toDateTimeImmutable(),
            signalCutoffAt: $now->toDateTimeImmutable(),
        );
        $extracted = $extractor->extract($observation, $now);

        // The extracted vector is exactly the frozen operational schema — no
        // outcome/verification key can appear because the observation carries none.
        $this->assertSame(
            AmReadinessFeatureSchema::FEATURES,
            array_keys($extracted['features']),
        );
        foreach (AmReadinessFeatureSchema::FORBIDDEN_SUBSTRINGS as $forbidden) {
            foreach (array_keys($extracted['features']) as $key) {
                $this->assertStringNotContainsString($forbidden, $key);
            }
        }
    }

    public function test_evaluation_reports_all_four_metrics_and_beats_the_naive_baseline(): void
    {
        $evaluation = $this->backtester()->buildArtifact()['evaluation'];

        $this->assertArrayHasKey('calibrationError', $evaluation);
        $this->assertArrayHasKey('discriminationAuc', $evaluation);
        $this->assertArrayHasKey('coverage', $evaluation);
        $this->assertArrayHasKey('naiveBaseline', $evaluation);

        $this->assertGreaterThan(0.5, $evaluation['discriminationAuc'], 'Model must discriminate better than chance.');
        $this->assertLessThan(0.15, $evaluation['calibrationError'], 'Calibration error should be small on the backtest.');
        $this->assertSame(1.0, $evaluation['coverage']['fraction']);
        $this->assertTrue($evaluation['beatsBaseline'], 'Model must beat or tie the naive base-rate baseline.');
        $this->assertGreaterThan(
            $evaluation['naiveBaseline']['discriminationAuc'],
            $evaluation['discriminationAuc'],
        );
        $this->assertLessThan(
            $evaluation['naiveBaseline']['brierScore'],
            $evaluation['brierScore'],
        );
    }

    public function test_backtest_is_deterministic(): void
    {
        $first = $this->backtester()->buildArtifact();
        $second = $this->backtester()->buildArtifact();

        $this->assertSame($first['model']['weights'], $second['model']['weights']);
        $this->assertSame($first['evaluation']['discriminationAuc'], $second['evaluation']['discriminationAuc']);
    }

    public function test_committed_artifact_is_present_labelled_synthetic_and_carries_full_provenance(): void
    {
        $artifact = AmReadinessModelArtifact::load();

        $this->assertTrue($artifact->isSynthetic());
        $this->assertNotSame('', $artifact->version());
        $this->assertSame(AmReadinessFeatureSchema::FEATURES, $artifact->featureSchema());
        $this->assertNotEmpty($artifact->trainingWindow());

        $provenance = $artifact->provenance();
        $this->assertTrue($provenance['synthetic']);
        $this->assertNotEmpty($provenance['syntheticLabel']);
        $this->assertNotEmpty($provenance['roundsCutoffLabel']);
        $this->assertArrayHasKey('calibrationError', $provenance['evaluation']);
        $this->assertArrayHasKey('discriminationAuc', $provenance['evaluation']);
        $this->assertArrayHasKey('coverage', $provenance['evaluation']);
        $this->assertArrayHasKey('naiveBaseline', $provenance['evaluation']);
    }

    public function test_stale_signal_yields_low_confidence_and_missing_signal_yields_unavailable(): void
    {
        $extractor = new AmReadinessFeatureExtractor;
        $now = CarbonImmutable::parse('2026-07-13T06:00:00Z');

        $stale = new AmReadinessObservation(
            orderId: 1,
            orderUuid: '00000000-0000-4000-8000-000000000001',
            stage: 'LAB_RESULTED',
            testFamily: 'blood_count',
            priority: 'urgent',
            patientClass: 'inpatient',
            isAmDraw: true,
            queueDepth: 4,
            analyzerDowntime: false,
            roundsCutoffAt: $now->addMinutes(60)->toDateTimeImmutable(),
            signalCutoffAt: $now->subMinutes(90)->toDateTimeImmutable(), // beyond the 20-min tolerance
        );
        $this->assertSame('low_confidence', $extractor->extract($stale, $now)['availability']);

        $missing = new AmReadinessObservation(
            orderId: 2,
            orderUuid: '00000000-0000-4000-8000-000000000002',
            stage: 'LAB_RECEIVED',
            testFamily: 'blood_count',
            priority: 'urgent',
            patientClass: 'inpatient',
            isAmDraw: true,
            queueDepth: null, // missing operational signal
            analyzerDowntime: null,
            roundsCutoffAt: $now->addMinutes(60)->toDateTimeImmutable(),
            signalCutoffAt: $now->toDateTimeImmutable(),
        );
        $extractedMissing = $extractor->extract($missing, $now);
        $this->assertSame('unavailable', $extractedMissing['availability']);
        $this->assertContains('queue_depth', $extractedMissing['missing']);
    }

    public function test_calibration_map_is_monotone_and_bounded(): void
    {
        $model = AmReadinessModelArtifact::load()->model();
        $this->assertInstanceOf(CalibratedLogisticModel::class, $model);

        $previous = -1.0;
        foreach ([0.0, 0.1, 0.25, 0.5, 0.75, 0.9, 1.0] as $raw) {
            $calibrated = $model->applyCalibration($raw);
            $this->assertGreaterThanOrEqual(0.0, $calibrated);
            $this->assertLessThanOrEqual(1.0, $calibrated);
            $this->assertGreaterThanOrEqual($previous - 1e-9, $calibrated, 'Calibration map must be monotone non-decreasing.');
            $previous = $calibrated;
        }
    }

    public function test_further_along_the_path_forecasts_higher_readiness(): void
    {
        $model = AmReadinessModelArtifact::load()->model();
        $extractor = new AmReadinessFeatureExtractor;
        $now = CarbonImmutable::parse('2026-07-13T06:00:00Z');

        $make = fn (string $stage): AmReadinessObservation => new AmReadinessObservation(
            orderId: 1,
            orderUuid: '00000000-0000-4000-8000-000000000001',
            stage: $stage,
            testFamily: 'metabolic_panel',
            priority: 'routine',
            patientClass: 'inpatient',
            isAmDraw: true,
            queueDepth: 4,
            analyzerDowntime: false,
            roundsCutoffAt: $now->addMinutes(120)->toDateTimeImmutable(),
            signalCutoffAt: $now->toDateTimeImmutable(),
        );

        $resulted = $model->calibratedProbability($extractor->extract($make('LAB_RESULTED'), $now)['features']);
        $ordered = $model->calibratedProbability($extractor->extract($make('LAB_ORDERED'), $now)['features']);

        $this->assertGreaterThan($ordered, $resulted, 'A resulted order should forecast higher on-time readiness than a not-yet-collected one.');
    }
}
