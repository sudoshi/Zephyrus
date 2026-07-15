<?php

namespace App\Console\Commands;

use App\Services\Radiology\BreachRisk\BreachRiskBacktester;
use App\Services\Radiology\BreachRisk\BreachRiskModelArtifact;
use Illuminate\Console\Command;

/**
 * Regenerates the committed synthetic Radiology breach-risk model artifact by
 * running the deterministic train → backtest pipeline. The artifact is checked
 * into the repository (config/radiology/breach_risk_model.json); this command
 * only needs to run when the feature schema or backtester changes.
 */
class RadiologyBuildBreachRiskModel extends Command
{
    protected $signature = 'radiology:build-breach-risk-model
        {--dry-run : Print the metrics without writing the artifact}';

    protected $description = 'Train and backtest the synthetic Radiology breach-risk model and write its versioned artifact.';

    public function handle(BreachRiskBacktester $backtester): int
    {
        $artifact = $backtester->buildArtifact();
        $evaluation = $artifact['evaluation'];

        $this->components->info(sprintf(
            'Model %s — AUC %.3f, calibration error %.3f, baseline AUC %.3f, beats baseline: %s',
            $artifact['modelVersion'],
            $evaluation['discriminationAuc'],
            $evaluation['calibrationError'],
            $evaluation['naiveBaseline']['discriminationAuc'],
            $evaluation['beatsBaseline'] ? 'yes' : 'no',
        ));

        if (! $evaluation['beatsBaseline']) {
            $this->components->error('Model does not beat the naive baseline; artifact NOT written.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $path = BreachRiskModelArtifact::path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->components->info('Wrote artifact to '.$path);

        return self::SUCCESS;
    }
}
