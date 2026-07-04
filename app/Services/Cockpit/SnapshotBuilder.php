<?php

namespace App\Services\Cockpit;

use App\Domain\Cockpit\Metrics\BaseMetrics;
use App\Domain\Cockpit\Metrics\EdMetrics;
use App\Domain\Cockpit\Metrics\FinancialMetrics;
use App\Domain\Cockpit\Metrics\FlowMetrics;
use App\Domain\Cockpit\Metrics\OkrMetrics;
use App\Domain\Cockpit\Metrics\PeriopMetrics;
use App\Domain\Cockpit\Metrics\QualityMetrics;
use App\Domain\Cockpit\Metrics\RtdcMetrics;
use App\Domain\Cockpit\Metrics\ServiceLineMetrics;
use App\Domain\Cockpit\Metrics\StaffingMetrics;
use App\Domain\Cockpit\SnapshotContext;
use App\Models\Cockpit\CockpitSnapshot;
use App\Models\Ops\MetricDefinition;
use App\Services\CommandCenterDataService;
use App\Services\Ops\Agents\AgentToolRegistry;
use App\Support\Cockpit\MetricValue;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the ONE server-computed cockpit snapshot (Zephyrus 2.0 P1).
 *
 * The legacy CommandCenterDataService::build() payload (frozen Zod contract —
 * /dashboard keeps working untouched until P2) is the base; the spec §3.2
 * sections are assembled ADDITIVELY on top from the Domain/Cockpit/Metrics
 * providers: facility, capacityStatus, asOf, census[8], okrs[9], domains{8},
 * and derived alerts. The Eddy capacity.snapshot document is embedded so the
 * cockpit, the drills, AND Eddy read the same numbers (the single-snapshot
 * discipline).
 *
 * Alerts are DERIVED, never hand-set: every warn/crit MetricValue whose
 * definition carries an alert_template, crit-first. Template presence is the
 * Earned-Red ration — MTD ledger measures without templates change color on
 * the wall but never enter the ticker (canon: no alarm-fatigue dashboard).
 *
 * Single-facility per decision D1: one replaced row keyed by the manifest's
 * facility code ('HOSP1'); no facilities table.
 */
class SnapshotBuilder
{
    public const CACHE_KEY = 'cockpit.snapshot';

    /** Slightly over the 60s refresh cadence so a slow job never leaves a gap. */
    public const CACHE_TTL_SECONDS = 90;

    /**
     * Serve-path staleness ceiling (P2): current() rebuilds inline when the
     * persisted row is older than two refresh cadences. On a host without the
     * scheduler cron this bounds /dashboard staleness at ~2 minutes instead of
     * forever — one full build per 2 minutes max, cheaper than the pre-2.0
     * build-per-request controller it replaces.
     */
    public const SERVE_MAX_AGE_SECONDS = 120;

    private const CENSUS_STRIP = [
        'rtdc.census', 'rtdc.available', 'rtdc.pending_admits', 'rtdc.pending_dc',
        'rtdc.boarders', 'rtdc.icu_occupancy', 'rtdc.blocked_beds', 'rtdc.occupancy',
    ];

    private const DOMAIN_GAUGES = [
        'rtdc' => 'rtdc.occupancy',
        'ed' => 'ed.nedocs',
        'periop' => 'periop.prime_util',
    ];

    public function __construct(
        private readonly CommandCenterDataService $commandCenter,
        private readonly AgentToolRegistry $tools,
        private readonly HospitalManifest $manifest,
        private readonly MetricValueWriter $writer,
        private readonly AlertEngine $alerts,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $facilityKey = null): array
    {
        return $this->buildWithContext($facilityKey)['payload'];
    }

    /**
     * Build, persist the single replaced row, prime the shared cache, and
     * append this snapshot's scalars to ops.metric_values history.
     *
     * @return array<string, mixed> the persisted payload
     */
    public function refresh(?string $facilityKey = null): array
    {
        $facilityKey ??= $this->manifest->facilityCode();

        ['payload' => $payload, 'context' => $ctx] = $this->buildWithContext($facilityKey);

        // P6: the persisted/served payload carries the flap-DAMPED open set
        // reconciled against cockpit_alerts, not the raw per-snapshot
        // candidates — the ticker never strobes. build() (preview/tests)
        // keeps the raw derivation.
        $payload['alerts'] = $this->alerts->reconcile($facilityKey, $payload['alerts']);

        // P6 WS-4: each open alert carries its matching catalog action so the
        // ticker can hand off to the EddyDock pre-seeded (client stays
        // presentation-only; the mapping lives server-side).
        $payload['alerts'] = array_map(function (array $alert): array {
            $action = \App\Services\Eddy\EddyActionService::actionForAlert($alert['key'], $alert['status']);

            return $alert + [
                'action' => $action,
                'actionLabel' => \App\Services\Eddy\EddyActionService::CATALOG[$action]['label'],
            ];
        }, $payload['alerts']);

        CockpitSnapshot::query()->updateOrCreate(
            ['facility_key' => $facilityKey],
            ['payload' => $payload, 'generated_at' => now()],
        );

        Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL_SECONDS);

        try {
            $this->writer->write($ctx->allEmitted(), $ctx->definitions, now());
        } catch (\Throwable $e) {
            Log::warning('cockpit.snapshot.metric_values_write_failed', ['error' => $e->getMessage()]);
        }

        // P6 WS-7: PHI-free reload ping on hospital.cockpit — clients refetch
        // the snapshot over their own session. Broadcast trouble (Reverb down,
        // BROADCAST_CONNECTION=null) must never fail the refresh itself.
        try {
            \App\Events\Cockpit\CockpitSnapshotUpdated::dispatch(
                $facilityKey,
                (string) ($payload['asOf'] ?? now()->toIso8601String()),
            );
        } catch (\Throwable $e) {
            Log::warning('cockpit.snapshot.broadcast_failed', ['error' => $e->getMessage()]);
        }

        return $payload;
    }

    /**
     * The serve path (P2): cache hit → fresh-enough persisted row → inline
     * refresh. Every reader of "the current snapshot" (the /dashboard page,
     * /api/cockpit/snapshot, and through them the drills and Eddy) resolves
     * through here so they can never disagree.
     *
     * @return array<string, mixed>
     */
    public function current(?string $facilityKey = null): array
    {
        $facilityKey ??= $this->manifest->facilityCode();

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['facilityKey'] ?? null) === $facilityKey) {
            return $cached;
        }

        $row = CockpitSnapshot::query()->find($facilityKey);

        if ($row !== null && $row->generated_at->gt(now()->subSeconds(self::SERVE_MAX_AGE_SECONDS))) {
            Cache::put(self::CACHE_KEY, $row->payload, self::CACHE_TTL_SECONDS);

            return $row->payload;
        }

        return $this->refresh($facilityKey);
    }

    /** @return array{payload: array<string, mixed>, context: SnapshotContext} */
    private function buildWithContext(?string $facilityKey = null): array
    {
        $facilityKey ??= $this->manifest->facilityCode();

        $payload = $this->commandCenter->build();
        $payload['facilityKey'] = $facilityKey;
        $payload['facilityName'] = $this->manifest->facilityName();
        // Own key — 'capacity' is the legacy Zod contract's capacity BAND and
        // must never be clobbered by the Eddy tool document.
        $payload['capacitySnapshot'] = $this->safeCapacity();

        $definitions = MetricDefinition::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('metric_key');

        $ctx = new SnapshotContext($payload, $definitions, now()->toIso8601String());

        $domains = [];
        $okrs = [];

        foreach ($this->providers() as $provider) {
            $values = $this->safeMetrics($provider, $ctx);

            if ($provider->domain() === 'okr') {
                $okrs = $values;

                continue;
            }

            $domains[$provider->domain()] = $this->domainSection($provider->domain(), $values);
        }

        if (config('cockpit.hide_demo_domains')) {
            $domains = array_filter($domains, fn (array $d): bool => $d['provenance'] !== 'demo');
        }

        $facility = $this->manifest->facility();

        $payload['asOf'] = $payload['generatedAtIso'] ?? $ctx->nowIso;
        $payload['facility'] = [
            'name' => $facility['name'] ?? $this->manifest->facilityName(),
            'licensedBeds' => $facility['licensed_beds'] ?? null,
            'level' => $facility['type'] ?? null,
        ];
        $payload['capacityStatus'] = $this->capacityStatus($payload['strain'] ?? []);
        $payload['census'] = $this->censusStrip($ctx);
        $payload['okrs'] = array_map(
            fn (MetricValue $mv): array => $this->okrCard($mv, $definitions),
            $okrs,
        );
        $payload['domains'] = $domains;
        $payload['alerts'] = $this->deriveAlerts($domains, $okrs, $ctx);

        return ['payload' => $payload, 'context' => $ctx];
    }

    /**
     * Provider order matters: OkrMetrics runs LAST so it can reuse values
     * emitted earlier in the same snapshot.
     *
     * @return list<BaseMetrics>
     */
    private function providers(): array
    {
        return [
            app(RtdcMetrics::class),
            app(EdMetrics::class),
            app(PeriopMetrics::class),
            app(StaffingMetrics::class),
            app(FlowMetrics::class),
            app(QualityMetrics::class),
            app(ServiceLineMetrics::class),
            app(FinancialMetrics::class),
            app(OkrMetrics::class),
        ];
    }

    /**
     * One failing domain must never blank the snapshot (PG 25P02 discipline).
     *
     * @return list<MetricValue>
     */
    private function safeMetrics(BaseMetrics $provider, SnapshotContext $ctx): array
    {
        try {
            return $provider->metrics($ctx);
        } catch (\Throwable $e) {
            Log::warning('cockpit.snapshot.domain_failed', [
                'domain' => $provider->domain(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<MetricValue>  $values
     * @return array{provenance: string, gaugeKey: ?string, tiles: list<array<string, mixed>>}
     */
    private function domainSection(string $domain, array $values): array
    {
        $demoCount = count(array_filter(
            $values,
            fn (MetricValue $v): bool => ($v->metadata['provenance'] ?? null) === 'demo',
        ));

        $provenance = match (true) {
            $values === [] => 'live',
            $demoCount === count($values) => 'demo',
            $demoCount > 0 => 'partial',
            default => 'live',
        };

        return [
            'provenance' => $provenance,
            'gaugeKey' => self::DOMAIN_GAUGES[$domain] ?? null,
            'tiles' => array_map(fn (MetricValue $v): array => $v->toArray(), $values),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function censusStrip(SnapshotContext $ctx): array
    {
        $strip = [];

        foreach (self::CENSUS_STRIP as $key) {
            $value = $ctx->emittedValue($key);

            if ($value !== null) {
                $strip[] = $value->toArray();
            }
        }

        return $strip;
    }

    /**
     * @param  Collection<string, MetricDefinition>  $definitions
     * @return array<string, mixed>
     */
    private function okrCard(MetricValue $mv, Collection $definitions): array
    {
        $definition = $definitions->get($mv->key);

        return [
            'objective' => ($definition?->metadata ?? [])['objective'] ?? null,
            'keyResult' => $mv->label,
            'owner' => $definition?->owner,
        ] + $mv->toArray();
    }

    /**
     * @param  array<string, array{tiles: list<array<string, mixed>>}>  $domains
     * @param  list<MetricValue>  $okrs
     * @return list<array<string, mixed>>
     */
    private function deriveAlerts(array $domains, array $okrs, SnapshotContext $ctx): array
    {
        $visibleKeys = [];

        foreach ($domains as $section) {
            foreach ($section['tiles'] as $tile) {
                $visibleKeys[$tile['key']] = true;
            }
        }

        foreach ($okrs as $okr) {
            $visibleKeys[$okr->key] = true;
        }

        $alerts = [];

        foreach ($ctx->allEmitted() as $key => $value) {
            if (! isset($visibleKeys[$key]) || ! $value->status->isAlerting()) {
                continue;
            }

            $template = $ctx->definition($key)?->alert_template;

            if ($template === null || $template === '') {
                continue;
            }

            $alert = [
                'key' => $key,
                'status' => $value->status->value,
                'text' => strtr($template, [
                    '{value}' => (string) $value->value,
                    '{display}' => $value->display,
                    '{label}' => $value->label,
                ]),
            ];

            if (($value->metadata['provenance'] ?? null) === 'demo') {
                $alert['provenance'] = 'demo';
            }

            $alerts[] = $alert;
        }

        usort($alerts, fn (array $a, array $b): int => ($a['status'] === 'crit' ? 0 : 1) <=> ($b['status'] === 'crit' ? 0 : 1));

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $strain
     * @return array{level: string, code: string, status: string}
     */
    private function capacityStatus(array $strain): array
    {
        $legacy = $strain['status'] ?? 'success';

        [$code, $status] = match ($legacy) {
            'critical' => ['red', 'crit'],
            'warning' => ['yellow', 'warn'],
            default => ['green', 'normal'],
        };

        return [
            'level' => (string) ($strain['label'] ?? 'Surge Level 0'),
            'code' => $code,
            'status' => $status,
        ];
    }

    /**
     * The capacity.snapshot tool document, embedded so Eddy's worldview and
     * the cockpit are the same snapshot. Fail-open: capacity trouble must
     * never blank the whole snapshot.
     *
     * @return array<string, mixed>|null
     */
    private function safeCapacity(): ?array
    {
        try {
            return $this->tools->capacitySnapshot();
        } catch (\Throwable $e) {
            Log::warning('cockpit.snapshot.capacity_unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
