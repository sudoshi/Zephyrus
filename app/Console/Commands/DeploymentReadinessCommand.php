<?php

namespace App\Console\Commands;

use App\Services\Deployment\DeploymentReadinessService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Evaluate the deployment-readiness scorecard (plan §16 Acceptance Criteria) for a
 * facility and its IDN. Exit code is 0 when deployment_ready, 1 otherwise, so it can gate
 * a deploy pipeline.
 *
 *   php artisan deployment:readiness SUMMIT_REGIONAL
 *   php artisan deployment:readiness SUMMIT_REGIONAL --json
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6)
 */
class DeploymentReadinessCommand extends Command
{
    protected $signature = 'deployment:readiness {facilityKey : hosp_org.facilities.facility_key} {--json : Emit the scorecard as JSON}';

    protected $description = 'Evaluate the deployment-readiness scorecard (Acceptance Criteria) for a facility.';

    public function handle(DeploymentReadinessService $service): int
    {
        $facilityKey = (string) $this->argument('facilityKey');

        try {
            $report = $service->evaluate($facilityKey);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->getOutput()->writeln(
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW
            );

            return $report['deployment_ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Deployment readiness — {$report['facility_name']} ({$facilityKey})");

        $this->table(
            ['#', 'Check', 'Status', 'Count'],
            array_map(fn (array $c): array => [
                $c['criterion'],
                $c['title'],
                strtoupper($c['status']),
                $c['count'],
            ], $report['checks'])
        );

        $s = $report['summary'];
        $this->line("pass={$s['pass']}  fail={$s['fail']}  warn={$s['warn']}  n/a={$s['not_applicable']}");

        if ($report['deployment_ready']) {
            $this->info('DEPLOYMENT READY ✓ (no hard failures)');

            return self::SUCCESS;
        }

        $this->error('NOT deployment ready — resolve the FAIL checks above.');

        return self::FAILURE;
    }
}
