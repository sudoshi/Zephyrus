<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use App\Services\Demo\DemoRefreshCoordinator;
use Illuminate\Console\Command;

/**
 * Canonical rolling-demo refresh (plan §6 / FEEDBACK Wave 1).
 *
 * One guarded, ledgered batch that re-anchors every time-sensitive demo domain to a single
 * frozen anchor, recomputes source freshness, validates, and publishes the cockpit snapshot
 * only if critical invariants pass. Scheduled every 15 minutes in demo mode so the demo stays
 * current unattended.
 *
 *   php artisan zephyrus:demo-refresh --validate           # the scheduled form
 *   php artisan zephyrus:demo-refresh --dry-run            # preview the flow shift, no writes
 *   php artisan zephyrus:demo-refresh --validate-only --json
 *
 * Refuses to mutate unless config('demo.enabled') (DEMO_MODE=true) or --force is set, so a
 * live/connector-backed deployment can never be overwritten by the synthetic pipeline.
 */
class DemoRefreshCommand extends Command
{
    protected $signature = 'zephyrus:demo-refresh
        {--anchor=now : Freeze the batch to this moment (ISO-8601 or "now")}
        {--window-hours=48 : Operational window width (±half around the anchor)}
        {--scenario= : Override the demo scenario key}
        {--validate : Run invariants as the publish gate (default)}
        {--validate-only : Run invariants only — no writes}
        {--dry-run : Preview the flow shift and invariants without writing}
        {--force : Permit a real refresh outside demo mode (logged)}
        {--json : Machine-readable output for the scheduler/monitor}';

    protected $description = 'Re-anchor, validate, and publish the rolling demo (invariant-gated). Non-zero exit on failure.';

    public function handle(DemoRefreshCoordinator $coordinator, DemoInvariantService $invariants): int
    {
        $clock = DemoClock::fromOption((string) $this->option('anchor'), (int) $this->option('window-hours'));

        if ($this->option('validate-only')) {
            return $this->emitValidation($clock, $invariants);
        }

        if ($this->option('dry-run')) {
            $this->line("Dry run @ {$clock->key()} — previewing flow shift, no writes:");
            $this->call('patient-flow:rebase-synthetic', ['--anchor' => $clock->key(), '--dry-run' => true]);
            $this->line((string) json_encode(['ancillary' => $coordinator->previewAncillary($clock)], JSON_UNESCAPED_SLASHES));
            $this->newLine();

            return $this->emitValidation($clock, $invariants);
        }

        $enabled = (bool) config('demo.enabled') || (bool) $this->option('force');
        if (! $enabled) {
            $this->error('Refusing to refresh: demo mode is off (set DEMO_MODE=true) and --force was not given.');

            return self::FAILURE;
        }
        if (! (bool) config('demo.enabled')) {
            $this->warn('Running with --force outside demo mode.');
        }

        $result = $coordinator->refresh($clock);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info("demo-refresh {$result['status']} @ {$result['anchor']}  ({$result['durationMs']}ms, refresh {$result['refreshId']})");
        foreach ($result['domains'] as $d) {
            $mark = $d['ok'] ? '<fg=green>ok</>' : '<fg=red>FAIL</>';
            $this->line(sprintf('  %s %-16s %5dms%s', $mark, $d['domain'], $d['ms'], $d['error'] ? '  '.$d['error'] : ''));
        }
        $inv = $result['invariants'];
        $this->line(sprintf('  invariants: %d checks, %d critical failed, %d warnings — cockpit %s',
            $inv['total'] ?? 0, $inv['criticalFailed'] ?? 0, $inv['warningFailed'] ?? 0,
            $result['published'] ? 'published' : 'NOT published (last-known-good preserved)'));

        if ($result['status'] !== 'passed') {
            $this->error("✗ {$result['error']}");

            return self::FAILURE;
        }
        $this->info('✓ demo current');

        return self::SUCCESS;
    }

    private function emitValidation(DemoClock $clock, DemoInvariantService $invariants): int
    {
        $findings = $invariants->run($clock);
        $ok = $invariants->passed($findings);
        $criticalFailed = count(array_filter($findings, fn ($f) => $f['severity'] === 'critical' && ! $f['passed']));
        $warningFailed = count(array_filter($findings, fn ($f) => $f['severity'] === 'warning' && ! $f['passed']));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'anchor' => $clock->key(), 'ok' => $ok,
                'criticalFailed' => $criticalFailed, 'warningFailed' => $warningFailed,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('validate @ %s — %d checks, %d critical failed, %d warnings',
                $clock->key(), count($findings), $criticalFailed, $warningFailed));
            foreach ($findings as $f) {
                if (! $f['passed']) {
                    $tag = $f['severity'] === 'critical' ? '<fg=red>✗</>' : '<fg=yellow>!</>';
                    $this->line("  {$tag} {$f['key']}: {$f['observed']}");
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
