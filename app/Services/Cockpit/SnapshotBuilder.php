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

        return $payload;
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
