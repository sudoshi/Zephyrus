<?php

namespace App\Services\Demo;

use App\Jobs\RefreshCockpitSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rolling-demo refresh coordinator (plan §6 / FEEDBACK Wave 1).
 *
 * Wraps the Wave-0 runbook into ONE guarded, ledgered batch so the demo stays current
 * unattended instead of re-staling in a few days. Execution order (plan §6.1):
 *   1. acquire a PostgreSQL advisory lock (the scheduler also uses withoutOverlapping);
 *   2. open the ops.demo_refresh_runs ledger row and freeze one anchor (DemoClock);
 *   3. run the domain refreshers at that anchor;
 *   4. recompute ops.source_freshness (otherwise it is only refreshed lazily on page loads);
 *   5. run the invariants;
 *   6. publish the cockpit snapshot ONLY if critical invariants pass — otherwise the last
 *      known-good snapshot is preserved and the batch is marked failed (§6.2);
 *   7. close the ledger row and return a structured summary.
 *
 * Only the cockpit PUBLISH is gated; the domain sources are already written by the time
 * invariants run (best-effort, non-transactional) — a failed batch alerts and the next
 * 15-minute run repairs it. Every mutation targets demo-owned rows (seeder ownership tags).
 */
final class DemoRefreshCoordinator
{
    private const LOCK_KEY = 728041;

    private const SEED_VERSION = '2026-07-10-wave1';

    public function __construct(private readonly DemoInvariantService $invariants)
    {
    }

    /**
     * @return array{refreshId:string,status:string,anchor:string,durationMs:int,
     *   domains:list<array<string,mixed>>,invariants:array<string,mixed>,published:bool,error:?string}
     */
    public function refresh(DemoClock $clock): array
    {
        if (! $this->acquireLock()) {
            return [
                'refreshId' => '', 'status' => 'locked', 'anchor' => $clock->key(), 'durationMs' => 0,
                'domains' => [], 'invariants' => [], 'published' => false,
                'error' => 'another demo-refresh holds the advisory lock',
            ];
        }

        $refreshId = (string) Str::uuid();
        $startedAt = now();
        $this->openLedger($refreshId, $clock, $startedAt);

        $domains = [];
        $error = null;
        $published = false;
        $status = 'running';
        $invariantSummary = [];

        try {
            $domains[] = $this->step('flow', fn () => Artisan::call('patient-flow:rebase-synthetic', ['--anchor' => $clock->key()]));
            $domains[] = $this->step('operational', fn () => Artisan::call('db:seed', ['--class' => 'CommandCenterDemoSeeder', '--force' => true]));
            $domains[] = $this->step('tuning', fn () => Artisan::call('db:seed', ['--class' => 'DemoTuningSeeder', '--force' => true]));
            // NB: no flow:snapshot --backfill here. The rebase already shifts the fixture
            // occupancy_snapshots to cover the trailing window; --backfill is an initial-seed
            // helper ("history on day one") and re-running it every cycle appends off-hour
            // duplicate checkpoints (the rebase nudges prior rows off their hour-aligned upsert
            // key). Ongoing current-hour capture is the separate hourly flow:snapshot schedule.
            $domains[] = $this->step('source_freshness', fn () => $this->refreshSourceFreshness());

            $findings = $this->invariants->run($clock);
            $critical = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'critical' && ! $f['passed']));
            $warnings = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'warning' && ! $f['passed']));
            $passed = $critical === [];
            $invariantSummary = [
                'total' => count($findings),
                'criticalFailed' => count($critical),
                'warningFailed' => count($warnings),
                'criticalKeys' => array_map(fn ($f) => $f['key'], $critical),
            ];

            if ($passed) {
                // Publish the aggregate only when the batch is coherent.
                dispatch_sync(new RefreshCockpitSnapshot);
                $published = true;
                $status = 'passed';
            } else {
                $status = 'failed';
                $error = 'critical invariants failed: '.implode(', ', $invariantSummary['criticalKeys']);
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        } finally {
            $this->closeLedger($refreshId, $status, $domains, $invariantSummary, $error);
            $this->releaseLock();
        }

        return [
            'refreshId' => $refreshId,
            'status' => $status,
            'anchor' => $clock->key(),
            'durationMs' => (int) $startedAt->diffInMilliseconds(now()),
            'domains' => $domains,
            'invariants' => $invariantSummary,
            'published' => $published,
            'error' => $error,
        ];
    }

    /** Recompute ops.source_freshness from each source's own registered column (plan §10.2). */
    public function refreshSourceFreshness(): void
    {
        $rows = DB::table('ops.source_freshness')->get();
        foreach ($rows as $row) {
            $schema = $this->safeIdent($row->source_schema);
            $table = $this->safeIdent($row->source_table);
            $col = $this->safeIdent($row->freshness_column);
            if ($schema === null || $table === null || $col === null) {
                continue;
            }

            $stat = DB::selectOne("SELECT count(*) AS n, max(\"{$col}\") AS latest FROM \"{$schema}\".\"{$table}\"");
            $count = (int) ($stat->n ?? 0);
            $latest = $stat->latest ?? null;

            $status = $this->freshnessStatus($latest, $count, (int) $row->expected_lag_minutes, (int) $row->warning_lag_minutes);

            DB::table('ops.source_freshness')->where('source_freshness_id', $row->source_freshness_id)->update([
                'latest_observed_at' => $latest,
                'record_count' => $count,
                'status' => $status,
                'checked_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ---- ledger ----

    private function openLedger(string $refreshId, DemoClock $clock, \Illuminate\Support\Carbon $startedAt): void
    {
        DB::table('ops.demo_refresh_runs')->insert([
            'refresh_id' => $refreshId,
            'scenario_key' => (string) config('demo.scenario', 'summit-reference'),
            'seed_version' => self::SEED_VERSION,
            'anchor_at' => $clock->anchor(),
            'window_start_at' => $clock->windowStart(),
            'window_end_at' => $clock->windowEnd(),
            'started_at' => $startedAt,
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function closeLedger(string $refreshId, string $status, array $domains, array $invariants, ?string $error): void
    {
        DB::table('ops.demo_refresh_runs')->where('refresh_id', $refreshId)->update([
            'status' => $status,
            'completed_at' => now(),
            'domain_results' => json_encode($domains),
            'invariant_results' => json_encode($invariants),
            'error_summary' => $error,
            'updated_at' => now(),
        ]);
    }

    // ---- helpers ----

    /** @return array{domain:string,ok:bool,ms:int,exit:?int,error:?string} */
    private function step(string $domain, callable $fn): array
    {
        $t0 = microtime(true);
        try {
            $exit = $fn();

            return ['domain' => $domain, 'ok' => ($exit === null || $exit === 0), 'ms' => (int) round((microtime(true) - $t0) * 1000), 'exit' => is_int($exit) ? $exit : null, 'error' => null];
        } catch (Throwable $e) {
            return ['domain' => $domain, 'ok' => false, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'exit' => null, 'error' => $e->getMessage()];
        }
    }

    private function freshnessStatus(mixed $latest, int $count, int $expectedLag, int $warningLag): string
    {
        if ($count === 0) {
            return 'warning';
        }
        if ($latest === null) {
            return 'critical';
        }
        $minutes = abs(now()->diffInMinutes(\Carbon\CarbonImmutable::parse($latest)));
        if ($minutes <= $expectedLag) {
            return 'success';
        }
        if ($minutes <= $warningLag) {
            return 'warning';
        }

        return 'critical';
    }

    private function safeIdent(?string $ident): ?string
    {
        return is_string($ident) && preg_match('/^[a-z_][a-z0-9_]*$/i', $ident) ? $ident : null;
    }

    private function acquireLock(): bool
    {
        $row = DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [self::LOCK_KEY]);

        return (bool) ($row->locked ?? false);
    }

    private function releaseLock(): void
    {
        DB::statement('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
    }
}
