<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use Illuminate\Console\Command;

/**
 * Read-only demo coherence + plausibility gate (a runtime companion to the B1 trust
 * foundation, which validates only in PHPUnit).
 *
 * Runs App\Services\Demo\DemoInvariantService against the current database and reports
 * temporal, capacity, freshness, and plausibility findings. SELECT-only — safe to run
 * against the canonical demo host after a demo reset / zephyrus:demo-roll-forward, or in CI.
 * Exit code:
 *   0  no critical finding failed
 *   1  a critical finding failed (or, with --strict, any finding failed)
 *
 *   php artisan zephyrus:demo-validate
 *   php artisan zephyrus:demo-validate --anchor=now --json
 */
class DemoValidateCommand extends Command
{
    protected $signature = 'zephyrus:demo-validate
        {--anchor=now : Anchor the checks to this moment (ISO-8601 or "now")}
        {--window-hours=48 : Operational window width (±half around the anchor)}
        {--strict : Treat warnings as failures too}
        {--json : Emit machine-readable JSON for schedulers/monitors}';

    protected $description = 'Validate demo-data coherence and plausibility (read-only). Non-zero exit on critical failure.';

    public function handle(DemoInvariantService $invariants): int
    {
        $clock = DemoClock::fromOption((string) $this->option('anchor'), (int) $this->option('window-hours'));
        $findings = $invariants->run($clock);

        $criticalFail = array_filter($findings, fn ($f) => $f['severity'] === 'critical' && ! $f['passed']);
        $warnFail = array_filter($findings, fn ($f) => $f['severity'] === 'warning' && ! $f['passed']);
        $ok = $invariants->passed($findings) && (! $this->option('strict') || $warnFail === []);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'anchor' => $clock->key(),
                'windowStart' => $clock->windowStart()->toIso8601String(),
                'windowEnd' => $clock->windowEnd()->toIso8601String(),
                'ok' => $ok,
                'counts' => [
                    'total' => count($findings),
                    'criticalFailed' => count($criticalFail),
                    'warningFailed' => count($warnFail),
                ],
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Demo validation @ {$clock->key()}  window {$clock->windowStart()->format('m-d H:i')} → {$clock->windowEnd()->format('m-d H:i')}");
        $this->newLine();

        $rows = [];
        foreach ($findings as $f) {
            $mark = $f['passed'] ? '<fg=green>PASS</>' : ($f['severity'] === 'critical' ? '<fg=red;options=bold>FAIL</>' : '<fg=yellow>WARN</>');
            $rows[] = [$mark, $f['category'], $f['key'], $f['observed'], $f['expected']];
        }
        $this->table(['', 'category', 'invariant', 'observed', 'expected'], $rows);

        $this->newLine();
        foreach ($findings as $f) {
            if (! $f['passed']) {
                $tag = $f['severity'] === 'critical' ? '<fg=red>✗ critical</>' : '<fg=yellow>! warning</>';
                $this->line("  {$tag} {$f['key']} — {$f['detail']}");
            }
        }

        $this->newLine();
        $summary = sprintf('%d checks · %d critical failures · %d warnings',
            count($findings), count($criticalFail), count($warnFail));
        $ok ? $this->info("✓ {$summary}") : $this->error("✗ {$summary}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
