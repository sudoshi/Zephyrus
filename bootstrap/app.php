<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$builder = Application::configure(basePath: dirname(__DIR__));

if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing') {
    $builder->create()->dontMergeFrameworkConfiguration();
}

return $builder
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin.scope' => \App\Http\Middleware\RequireAdminScope::class,
        ]);

        $middleware->prepend(\App\Http\Middleware\AssignRequestIdentity::class);

        $middleware->append([
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\AuditUserRequests::class,
            \App\Http\Middleware\EnsureClinicalFailureOutputSafe::class,
        ]);

        $middleware->api(
            append: [
                \App\Http\Middleware\EnforceApiIngressContract::class,
            ]
        );

        $middleware->web(
            append: [
                \App\Http\Middleware\EnsureSessionIsCurrent::class,
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Services\Auth\StepUpRequired $exception, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'step_up_required',
                        'message' => $exception->getMessage(),
                        'reauthentication_url' => route('password.confirm'),
                    ],
                ], 428);
            }

            return redirect()->guest(route('password.confirm'))
                ->with('status', $exception->getMessage());
        });
        $exceptions->render(function (\App\Services\Governance\GovernanceViolation $exception, \Illuminate\Http\Request $request) {
            $status = match ($exception->reason) {
                'authorization_denied', 'actor_missing' => 403,
                'subject_invalid', 'reason_invalid', 'payload_hash_invalid' => 422,
                default => 409,
            };

            return response()->json([
                'error' => [
                    'code' => $exception->reason,
                    'message' => $exception->getMessage(),
                ],
            ], $status);
        });
        $exceptions->render(function (\App\Security\ClinicalPayloads\ClinicalPayloadException $exception) {
            $code = explode(':', $exception->errorCode, 2)[0];
            $status = match ($code) {
                'clinical_payload_authority_mismatch',
                'clinical_payload_quarantine_missing',
                'clinical_payload_quarantine_authority_mismatch' => 404,
                'clinical_payload_kind_invalid',
                'clinical_payload_classification_invalid',
                'clinical_payload_content_type_invalid',
                'clinical_payload_retention_policy_invalid',
                'clinical_payload_quarantine_reason_invalid',
                'clinical_payload_quarantine_details_invalid',
                'clinical_payload_quarantine_inbound_mismatch',
                'clinical_payload_governance_reference_invalid' => 422,
                'clinical_content_output_rejected',
                'clinical_content_audit_rejected',
                'clinical_content_alert_rejected',
                'clinical_content_evidence_rejected',
                'clinical_content_governance_rejected',
                'clinical_content_quarantine_rejected',
                'clinical_payload_queue_contract_invalid',
                'clinical_payload_queue_encryption_required',
                'clinical_payload_queue_payload_rejected' => 422,
                'clinical_payload_disk_unavailable',
                'clinical_payload_storage_write_failed',
                'clinical_payload_storage_delete_failed',
                'clinical_payload_key_provider_unavailable',
                'clinical_payload_read_failed',
                'clinical_payload_store_failed' => 503,
                default => 409,
            };
            $message = match ($code) {
                'clinical_payload_authority_mismatch',
                'clinical_payload_quarantine_missing',
                'clinical_payload_quarantine_authority_mismatch' => 'The requested clinical-payload authority does not exist in the selected source boundary.',
                'clinical_payload_deletion_blocked' => 'The clinical payload has unresolved operational dependencies and cannot be purged.',
                'clinical_payload_legal_hold_active' => 'An active legal hold blocks this clinical-payload operation.',
                'clinical_payload_storage_delete_failed' => 'Encrypted-object deletion failed closed and remains pending for governed retry.',
                default => 'The clinical-payload operation failed its governed safety contract.',
            };

            return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
        });
        $exceptions->render(function (\App\Services\Authorization\AdminScopeViolation $exception, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $exception->reason,
                        'message' => $exception->getMessage(),
                    ],
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new \App\Jobs\ReconcileRtdcPredictions)->dailyAt('02:00');
        // Flow Window hourly checkpoints (census + per-space occupancy) — W2.
        $schedule->command('flow:snapshot')->hourly();
        // Zephyrus 2.0 P1: the ONE server-computed cockpit snapshot —
        // /api/cockpit/snapshot becomes a pure cache lookup. Requires a
        // running queue worker + schedule runner in prod.
        $schedule->job(new \App\Jobs\RefreshCockpitSnapshot)->everyMinute()->withoutOverlapping();
        // Zephyrus 2.0 P7 (WS-5): the heavy MTD Quality/Service/Financial
        // materialized views refresh CONCURRENTLY hourly — off the per-minute
        // snapshot path so the wall never waits on a full aggregate.
        $schedule->job(new \App\Jobs\RefreshCockpitMaterializedViews)->hourly()->withoutOverlapping();
        // Retention for the snapshot scalars the refresh job writes — the
        // writer and the pruner ship together (P1 execution notes).
        $schedule->job(new \App\Jobs\PruneCockpitMetricValues)->dailyAt('03:40');
        // Zephyrus 2.0 Part X (X0): the object-centric event log (OCEL 2.0)
        // incremental projection — read-side, additive, PHI-safe. Runs on the
        // SAME clock as the cockpit snapshot so A0 (cockpit) and A3 (Arena)
        // never disagree (Principle 7). Idempotent upserts, so the 2-day
        // trailing window overlapping the prior run is harmless.
        $schedule->job(new \App\Jobs\RefreshOcelLog)->everyFifteenMinutes()->withoutOverlapping();
        // Nightly full reconcile: re-project the trailing window and diff
        // projected counts against prod.*/flow_core.* source counts (§X.3.3).
        $schedule->command('ocel:project --days=90 --reconcile')->dailyAt('02:30');
        if (config('demo_data.enabled') && config('demo_data.schedule_enabled')) {
            $schedule->command('zephyrus:demo-roll-forward --commit')
                ->dailyAt((string) config('demo_data.schedule_time', '05:15'))
                ->withoutOverlapping();
        }
        // Zephyrus 2.0 Part X (X3): recompute care-pathway conformance on its own
        // cadence and cache the rate for the cockpit. ARENA_ENABLED-gated (no-op
        // when off). Off the per-minute snapshot path — the heavy sidecar call
        // never blocks the wall.
        $schedule->job(new \App\Jobs\RefreshArenaConformance)->everyThirtyMinutes()->withoutOverlapping();
        // Zephyrus 2.0 Part X (X2): recompute object-centric performance (OPerA)
        // and cache the worst hand-off synchronization wait as a flow-domain
        // cockpit tile. Same ARENA_ENABLED gate + off-snapshot cadence discipline.
        $schedule->job(new \App\Jobs\RefreshArenaPerformance)->everyThirtyMinutes()->withoutOverlapping();
        // Flow Reconciliation: rebuild the 48-Hour Flow Review baseline artifact
        // (arena.reviews) on a slow cadence — the window is 48h wide, so a 6-hourly
        // refresh keeps GET /api/arena/review fresh without hammering the sidecar.
        // The command no-ops when ARENA_ENABLED is off; the huddle's Run-review is
        // the other trigger. Needs a running schedule runner.
        $schedule->command('arena:review:run')->everySixHours()->withoutOverlapping();

        // FEEDBACK Wave 1: the rolling-demo refresh re-anchors every time-sensitive domain to
        // "now", recomputes source freshness, validates, and (only if critical invariants pass)
        // republishes the cockpit snapshot — so the demo never re-stales unattended. Gated on
        // demo mode so a live/connector-backed deployment is never overwritten by the synthetic
        // pipeline. withoutOverlapping guards a single server; the coordinator's PostgreSQL
        // advisory lock guards across servers. Needs a running schedule runner + queue worker.
        if (config('demo.enabled')) {
            // Every 6h: this deployment is a demo/investor environment, not a live
            // hospital, so re-anchoring the whole demo to "now" a few times a day keeps
            // it fresh for a walkthrough without the churn/overlap of a 15-minute loop.
            $schedule->command('zephyrus:demo-refresh --validate')
                ->everySixHours()
                ->withoutOverlapping(30); // 30-min lock expiry so a crashed run never wedges the next window

            // FEEDBACK Wave 5: nightly retention so the unattended 15-min refresh doesn't grow the
            // demo DB without bound (census/occupancy checkpoints + the refresh ledger).
            $schedule->command('zephyrus:demo-prune')->dailyAt('03:20')->withoutOverlapping();
        }

        // Governed integration runtime: dispatch protocol checks to the dedicated
        // database-backed queue. Health observations never advance data cursors.
        $schedule->job(new \App\Jobs\DispatchScheduledIntegrationHealthChecks)
            ->everyFiveMinutes()->withoutOverlapping();
        $schedule->job(new \App\Jobs\DispatchScheduledFhirPolls)
            ->everyFifteenMinutes()->withoutOverlapping();
        // Append-only, PHI-free evidence for the Admin System Health surface.
        // This must run synchronously on the scheduler so its own heartbeat does
        // not depend on a queue worker being healthy.
        $schedule->command('admin:observe-system-health')
            ->everyMinute()->withoutOverlapping(5);
        // Source-scoped SLO evidence is also collected synchronously: queue
        // degradation is an input to the observation and cannot be allowed to
        // prevent the observation itself from being persisted.
        $schedule->command('integrations:observe-source-health --limit=250')
            ->everyMinute()->onOneServer()->withoutOverlapping(5);
        // Credential-rotation threshold crossings page through the shared on-call
        // dispatcher (INT-SECRET). Thresholds are day-granular and the per-band
        // dedupe ledger keeps this to one page per crossing, so a daily sweep is
        // sufficient; channels are inert by default.
        $schedule->command('integrations:dispatch-credential-rotation-alerts --limit=500')
            ->dailyAt('06:20')->onOneServer()->withoutOverlapping(30);
        // Encrypted clinical payload lifecycle. These commands expose counts
        // and stable error codes only; no payload content enters scheduler state.
        $schedule->command('clinical-payloads:lifecycle --execute --limit=100')
            ->hourly()->onOneServer()->withoutOverlapping(30);
        $schedule->command('clinical-payloads:verify')
            ->dailyAt('02:40')->onOneServer()->withoutOverlapping(120);
        // Flow Reconciliation: rebuild the 48-Hour Flow Review baseline artifact
        // (arena.reviews) on a slow cadence — the window is 48h wide, so a 6-hourly
        // refresh keeps GET /api/arena/review fresh without hammering the sidecar.
        // The command no-ops when ARENA_ENABLED is off; the huddle's Run-review is
        // the other trigger. Needs a running schedule runner.
        $schedule->command('arena:review:run')->everySixHours()->withoutOverlapping();
        if (config('staffing.materialization_schedule_enabled', true)) {
            $schedule->command('staffing:materialize-canonical')
                ->dailyAt((string) config('staffing.materialization_schedule_time', '04:10'))
                ->withoutOverlapping(180)
                ->runInBackground();
        }
    })
    ->create();
