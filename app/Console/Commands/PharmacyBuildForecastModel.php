<?php

namespace App\Console\Commands;

use App\Services\Pharmacy\Forecast\PharmacyForecastBacktester;
use App\Services\Pharmacy\Forecast\PharmacyForecastModelArtifact;
use Illuminate\Console\Command;

final class PharmacyBuildForecastModel extends Command
{
    protected $signature = 'pharmacy:build-forecast-model {--dry-run : Print metrics without writing the artifact}';

    protected $description = 'Backtest and write the synthetic Pharmacy queue and stockout planning artifact.';

    public function handle(PharmacyForecastBacktester $backtester): int
    {
        $artifact = $backtester->buildArtifact();
        $queue = $artifact['queue']['evaluation'];
        $stockout = $artifact['stockout']['evaluation'];
        $this->components->info(sprintf(
            '%s — queue MAE %.3f (beats both: %s); stockout AUC %.3f / Brier %.3f (beats base rate: %s)',
            $artifact['modelVersion'],
            $queue['mae'],
            $queue['beatsBaselines'] ? 'yes' : 'no',
            $stockout['discriminationAuc'],
            $stockout['brierScore'],
            $stockout['beatsBaseline'] ? 'yes' : 'no',
        ));
        if (! $queue['beatsBaselines'] || ! $stockout['beatsBaseline']) {
            $this->components->error('One or more declared baselines were not beaten; artifact NOT written.');

            return self::FAILURE;
        }
        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $path = PharmacyForecastModelArtifact::path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->components->info('Wrote artifact to '.$path);

        return self::SUCCESS;
    }
}
