# Hospital Operations Command Center — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the perioperative-only main dashboard (`/dashboard`) with a house-wide Hospital Operations Command Center — a bento command-wall over a four-band (Capacity → Flow → Outcomes → Forecast) board with OKR-aware tiles, a multi-role view switcher, and drill-down into every domain.

**Architecture:** A backend `CommandCenterDataService` emits a single typed, camelCase payload (the live-query seam; deterministic synthesis this phase). `CommandCenterController` renders the Inertia page `Dashboard/CommandCenter`. The React page validates the payload with a Zod schema (which also generates the TypeScript types), then composes small focused components: `KpiTile` (the workhorse tile), `StrainIndex`, `Band`, `UnitHeatStrip`, `ForecastCurve`, `RoleSwitcher` (+ Zustand store), `OkrScoreboard`, `HeroWall`, and a layout-free `CommandCenterView` (for testability). The current perioperative view is preserved at `/dashboard/perioperative`; `/home` still serves the superuser landing.

**Tech Stack:** Laravel 11 / PHP 8.4 (PHPUnit, Inertia server-side render), React 19 + TypeScript (strict) + Inertia v2, Zustand v5, Zod v4, Recharts v2, TailwindCSS, Vitest + React Testing Library.

**Reference spec:** `docs/superpowers/specs/2026-06-22-command-center-dashboard-design.md`

**Conventions verified in this codebase:**
- Inertia pages resolve via `import.meta.glob('./Pages/**/*.{jsx,tsx}')`, preferring `.tsx` (see `resources/js/app.tsx`). A page component **must be a `default` export**.
- Sub-components use **named exports** (e.g. `@/Components/RTDC/RecommendationCard`).
- Dashboard pages wrap content in `DashboardLayout` + `PageContentLayout` + `<Head>` (see `resources/js/Pages/Dashboard/RTDC.jsx`).
- `@` alias → `resources/js` (both `vite.config.js` and `vitest.config.ts`).
- Vitest config: `tests/js/**/*.test.{ts,tsx}`, `jsdom`, setup at `tests/js/setup.ts` which **mocks** `@inertiajs/react` (`router`, `Link`→`<a>`, `Head`→null, `usePage`), `localStorage`, `matchMedia`, and `ResizeObserver`.
- Styling uses confirmed CSS variable tokens from `resources/css/tokens-dark.css`: surfaces `--surface-base|raised|overlay|elevated|highlight`, text `--text-primary|secondary|muted|ghost`, brand `--primary` `--accent`, semantic `--critical` `--warning` `--success` `--info`. **Use these via inline `style` for status color** (robust against Tailwind purge); use Tailwind utility classes for layout/spacing.
- Run a single JS test: `npx vitest run <path>`. Run all JS tests: `npm test`. Run PHP tests: `php artisan test --filter=<TestClass>`. Run Pint: `vendor/bin/pint app/...`.

---

## File Structure

**New — Frontend**
- `resources/js/types/commandCenter.ts` — Zod schemas + inferred TS types + `parseCommandCenterData()`. The single source of the data contract.
- `resources/js/Components/CommandCenter/status.ts` — `STATUS_VAR` map (StatusLevel → CSS var).
- `resources/js/Components/CommandCenter/KpiTile.tsx` — OKR-aware metric tile (value, target, sparkline, status, info, optional drill link).
- `resources/js/Components/CommandCenter/StrainIndex.tsx` — house surge/strain hero cell.
- `resources/js/Components/CommandCenter/Band.tsx` — band header + drill link + tile grid (supports subgroups).
- `resources/js/Components/CommandCenter/UnitHeatStrip.tsx` — compact per-unit census heat row.
- `resources/js/Components/CommandCenter/ForecastCurve.tsx` — 24h occupancy area chart + textual summary.
- `resources/js/Components/CommandCenter/RoleSwitcher.tsx` — Command/Executive/Service-line tabs.
- `resources/js/Components/CommandCenter/OkrScoreboard.tsx` — objective/KR progress board (Executive mode).
- `resources/js/Components/CommandCenter/HeroWall.tsx` — bento hero (StrainIndex + hero tiles; OKR board in Executive mode).
- `resources/js/Components/CommandCenter/CommandCenterView.tsx` — layout-free composition (testable).
- `resources/js/stores/commandCenterStore.ts` — Zustand store (role, serviceLine).
- `resources/js/Pages/Dashboard/CommandCenter.tsx` — Inertia page: parse payload, refresh timer, wrap View in layout.

**New — Backend**
- `app/Services/CommandCenterDataService.php` — deterministic representative-data builder (live-query seam).
- `app/Http/Controllers/CommandCenterController.php` — renders the page with the payload.

**New — Tests**
- `tests/js/commandCenter/fixture.ts` — a valid `CommandCenterData` fixture for component tests.
- `tests/js/commandCenter/{contract,KpiTile,StrainIndex,Band,UnitHeatStrip,ForecastCurve,RoleSwitcher,OkrScoreboard,HeroWall,CommandCenterView}.test.tsx`
- `tests/Feature/CommandCenterDataServiceTest.php`
- `tests/Feature/CommandCenterControllerTest.php`

**Modified**
- `routes/web.php` — repoint `/dashboard` to `CommandCenterController@index`; add the `use` import. Leave `/home` and `/dashboard/perioperative` intact.

---

## Task 1: Data contract — Zod schema + TS types

**Files:**
- Create: `resources/js/types/commandCenter.ts`
- Test: `tests/js/commandCenter/contract.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/contract.test.tsx
import { describe, it, expect } from 'vitest';
import { parseCommandCenterData, commandCenterDataSchema } from '@/types/commandCenter';

const minimalMetric = {
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%',
  target: 85, targetDisplay: '≤85%', status: 'warning',
  trajectory: { points: [80, 84, 88], direction: 'up', goodWhenDown: true },
  drillHref: '/rtdc/bed-tracking', definition: 'Staffed occupancy.',
};
const band = {
  key: 'capacity', title: 'Capacity', summary: '88% occupied', drillHref: '/rtdc/bed-tracking',
  drillLabel: 'open RTDC', metrics: [minimalMetric],
};
const valid = {
  generatedAtIso: '2026-06-22T12:00:00Z',
  strain: { level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
    drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: '2026-06-22T12:00:00Z' },
  heroMetrics: [minimalMetric],
  capacity: band, flow: { ...band, key: 'flow', title: 'Flow' },
  outcomes: { ...band, key: 'outcomes', title: 'Outcomes' },
  forecast: { ...band, key: 'forecast', title: 'Forecast' },
  forecastDetail: { predictedDischarges24h: 22, predictedDischarges48h: 40, predictedEdArrivals: 60,
    predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
    occupancyCurve: [{ hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 }],
    netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }] },
  unitCensus: [{ unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' }],
  objectives: [{ key: 'flow', title: 'Improve access & flow',
    keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' }] }],
};

describe('command center contract', () => {
  it('parses a valid payload', () => {
    const parsed = parseCommandCenterData(valid);
    expect(parsed.strain.level).toBe(2);
    expect(parsed.flow.title).toBe('Flow');
  });

  it('rejects an invalid payload (bad status enum)', () => {
    const bad = { ...valid, strain: { ...valid.strain, status: 'purple' } };
    expect(() => parseCommandCenterData(bad)).toThrow();
  });

  it('rejects a missing band', () => {
    const { forecast, ...rest } = valid;
    expect(commandCenterDataSchema.safeParse(rest).success).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/contract.test.tsx`
Expected: FAIL — `Cannot find module '@/types/commandCenter'`.

- [ ] **Step 3: Write the contract**

```ts
// resources/js/types/commandCenter.ts
import { z } from 'zod';

export const statusLevels = ['critical', 'warning', 'success', 'info', 'neutral'] as const;
export type StatusLevel = (typeof statusLevels)[number];

export const trajectorySchema = z.object({
  points: z.array(z.number()),
  direction: z.enum(['up', 'down', 'flat']),
  goodWhenDown: z.boolean(),
});
export type Trajectory = z.infer<typeof trajectorySchema>;

export const kpiMetricSchema = z.object({
  key: z.string(),
  label: z.string(),
  value: z.number(),
  unit: z.string(),
  display: z.string(),
  target: z.number().nullable(),
  targetDisplay: z.string().nullable(),
  status: z.enum(statusLevels),
  trajectory: trajectorySchema.nullable(),
  drillHref: z.string().nullable(),
  definition: z.string(),
});
export type KpiMetric = z.infer<typeof kpiMetricSchema>;

export const strainStateSchema = z.object({
  level: z.number(),
  label: z.string(),
  status: z.enum(statusLevels),
  previousLevel: z.number(),
  drivers: z.array(z.object({ label: z.string(), value: z.string(), status: z.enum(statusLevels) })),
  updatedAtIso: z.string(),
});
export type StrainState = z.infer<typeof strainStateSchema>;

export const unitCensusSchema = z.object({
  unitId: z.number(),
  name: z.string(),
  type: z.string(),
  staffed: z.number(),
  occupied: z.number(),
  blocked: z.number(),
  available: z.number(),
  occupancyPct: z.number(),
  acuityAdjustedPct: z.number(),
  status: z.enum(statusLevels),
});
export type UnitCensus = z.infer<typeof unitCensusSchema>;

export const forecastStateSchema = z.object({
  predictedDischarges24h: z.number(),
  predictedDischarges48h: z.number(),
  predictedEdArrivals: z.number(),
  predictedAdmissions: z.number(),
  netBedPosition: z.number(),
  surgeProbabilityPct: z.number(),
  occupancyCurve: z.array(z.object({
    hourOffset: z.number(), occupancyPct: z.number(), lowerPct: z.number(), upperPct: z.number(),
  })),
  netBedByUnit: z.array(z.object({ unitId: z.number(), name: z.string(), net: z.number() })),
});
export type ForecastState = z.infer<typeof forecastStateSchema>;

export const objectiveSchema = z.object({
  key: z.string(),
  title: z.string(),
  keyResults: z.array(z.object({
    label: z.string(), current: z.number(), target: z.number(), baseline: z.number(),
    progressPct: z.number(), status: z.enum(statusLevels), display: z.string(),
  })),
});
export type Objective = z.infer<typeof objectiveSchema>;

export const bandKeys = ['capacity', 'flow', 'outcomes', 'forecast'] as const;
export const bandDataSchema = z.object({
  key: z.enum(bandKeys),
  title: z.string(),
  summary: z.string(),
  drillHref: z.string(),
  drillLabel: z.string(),
  metrics: z.array(kpiMetricSchema),
  subgroups: z.array(z.object({
    key: z.string(), label: z.string(), metrics: z.array(kpiMetricSchema),
  })).optional(),
});
export type BandData = z.infer<typeof bandDataSchema>;

export const commandCenterDataSchema = z.object({
  generatedAtIso: z.string(),
  strain: strainStateSchema,
  heroMetrics: z.array(kpiMetricSchema),
  capacity: bandDataSchema,
  flow: bandDataSchema,
  outcomes: bandDataSchema,
  forecast: bandDataSchema,
  forecastDetail: forecastStateSchema,
  unitCensus: z.array(unitCensusSchema),
  objectives: z.array(objectiveSchema),
});
export type CommandCenterData = z.infer<typeof commandCenterDataSchema>;

export function parseCommandCenterData(input: unknown): CommandCenterData {
  return commandCenterDataSchema.parse(input);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/contract.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/types/commandCenter.ts tests/js/commandCenter/contract.test.tsx
git commit -m "feat(dashboard): command center data contract (zod schema + types)"
```

---

## Task 2: Backend representative-data service

**Files:**
- Create: `app/Services/CommandCenterDataService.php`
- Test: `tests/Feature/CommandCenterDataServiceTest.php`

The service is **pure/deterministic** this phase (no DB), so it is trivially testable and the live-query swap is isolated to this one class. Keys are camelCase to match the TS contract exactly.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/CommandCenterDataServiceTest.php
namespace Tests\Feature;

use App\Services\CommandCenterDataService;
use Tests\TestCase;

class CommandCenterDataServiceTest extends TestCase
{
    public function test_build_returns_all_top_level_keys(): void
    {
        $data = (new CommandCenterDataService())->build();

        foreach (['generatedAtIso', 'strain', 'heroMetrics', 'capacity', 'flow',
                  'outcomes', 'forecast', 'forecastDetail', 'unitCensus', 'objectives'] as $key) {
            $this->assertArrayHasKey($key, $data, "missing key: {$key}");
        }
    }

    public function test_bands_have_correct_keys_and_flow_has_subgroups(): void
    {
        $data = (new CommandCenterDataService())->build();

        $this->assertSame('capacity', $data['capacity']['key']);
        $this->assertSame('flow', $data['flow']['key']);
        $this->assertSame('outcomes', $data['outcomes']['key']);
        $this->assertSame('forecast', $data['forecast']['key']);
        $this->assertArrayHasKey('subgroups', $data['flow']);
        $this->assertGreaterThanOrEqual(3, count($data['flow']['subgroups'])); // ED, IP, OR
    }

    public function test_strain_level_is_derived_from_drivers(): void
    {
        $data = (new CommandCenterDataService())->build();

        $this->assertIsInt($data['strain']['level']);
        $this->assertGreaterThanOrEqual(0, $data['strain']['level']);
        $this->assertLessThanOrEqual(4, $data['strain']['level']);
        $this->assertNotEmpty($data['strain']['drivers']);
    }

    public function test_hero_metrics_include_occupancy_and_net_beds(): void
    {
        $data = (new CommandCenterDataService())->build();
        $keys = array_column($data['heroMetrics'], 'key');

        $this->assertContains('occupancy', $keys);
        $this->assertContains('net_beds', $keys);
    }

    public function test_every_kpi_metric_has_required_shape(): void
    {
        $data = (new CommandCenterDataService())->build();
        $required = ['key', 'label', 'value', 'unit', 'display', 'target',
                     'targetDisplay', 'status', 'trajectory', 'drillHref', 'definition'];

        foreach ($data['heroMetrics'] as $m) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $m, "metric missing {$field}");
            }
            $this->assertContains($m['status'], ['critical', 'warning', 'success', 'info', 'neutral']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CommandCenterDataServiceTest`
Expected: FAIL — `Class "App\Services\CommandCenterDataService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/CommandCenterDataService.php
namespace App\Services;

/**
 * Builds the Hospital Operations Command Center payload.
 *
 * This phase synthesizes a realistic, internally-consistent representative
 * dataset (no DB dependency) shaped exactly like the frontend Zod contract
 * (resources/js/types/commandCenter.ts), using camelCase keys.
 *
 * LIVE-DATA SEAM: a follow-up phase replaces the synthesis in build() with
 * real aggregate queries over prod.census_snapshots / encounters / beds /
 * operational_events / rtdc_predictions. The returned shape MUST NOT change.
 */
class CommandCenterDataService
{
    /** @return array<string,mixed> */
    public function build(): array
    {
        $units = $this->unitCensus();
        $occupancyPct = 88;
        $boarding = 4;
        $pendingAdmits = 11;
        $netBeds = -3;

        $strain = $this->strain($occupancyPct, $boarding, $pendingAdmits);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'strain' => $strain,
            'heroMetrics' => $this->heroMetrics($occupancyPct, $netBeds, $boarding),
            'capacity' => $this->capacityBand($units),
            'flow' => $this->flowBand(),
            'outcomes' => $this->outcomesBand(),
            'forecast' => $this->forecastBand($netBeds),
            'forecastDetail' => $this->forecastDetail($netBeds, $units),
            'unitCensus' => $units,
            'objectives' => $this->objectives(),
        ];
    }

    /** @return array<string,mixed> */
    private function metric(
        string $key, string $label, float|int $value, string $unit, string $display,
        float|int|null $target, ?string $targetDisplay, string $status,
        ?array $points, string $direction, bool $goodWhenDown, ?string $drillHref, string $definition,
    ): array {
        return [
            'key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'display' => $display,
            'target' => $target, 'targetDisplay' => $targetDisplay, 'status' => $status,
            'trajectory' => $points === null ? null
                : ['points' => $points, 'direction' => $direction, 'goodWhenDown' => $goodWhenDown],
            'drillHref' => $drillHref, 'definition' => $definition,
        ];
    }

    /** @return array<string,mixed> */
    private function strain(int $occupancyPct, int $boarding, int $pendingAdmits): array
    {
        // Composite strain score 0..4 from the three drivers.
        $score = 0;
        $score += $occupancyPct >= 92 ? 2 : ($occupancyPct >= 85 ? 1 : 0);
        $score += $boarding >= 6 ? 1 : 0;
        $score += $pendingAdmits >= 10 ? 1 : 0;
        $level = max(0, min(4, $score));
        $status = $level >= 3 ? 'critical' : ($level >= 2 ? 'warning' : 'success');

        return [
            'level' => $level,
            'label' => "Surge Level {$level}",
            'status' => $status,
            'previousLevel' => max(0, $level - 1),
            'drivers' => [
                ['label' => 'Occupancy', 'value' => "{$occupancyPct}%", 'status' => $occupancyPct >= 85 ? 'warning' : 'success'],
                ['label' => 'ED boarding', 'value' => (string) $boarding, 'status' => $boarding >= 6 ? 'critical' : 'warning'],
                ['label' => 'Pending admits', 'value' => (string) $pendingAdmits, 'status' => $pendingAdmits >= 10 ? 'warning' : 'success'],
            ],
            'updatedAtIso' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function heroMetrics(int $occupancyPct, int $netBeds, int $boarding): array
    {
        return [
            $this->metric('occupancy', 'Occupancy', $occupancyPct, '%', "{$occupancyPct}%",
                85, '≤85%', 'warning', [82, 84, 85, 87, 88], 'up', true, '/rtdc/bed-tracking',
                'Staffed beds occupied as a percent of staffed capacity. Safe zone ≤85%.'),
            $this->metric('net_beds', 'Net Bed Position', $netBeds, 'beds', (string) $netBeds,
                0, '≥0', 'critical', [2, 1, 0, -2, -3], 'down', false, '/rtdc/predictions/demand',
                'Projected available minus projected demand over the next 4–8h.'),
            $this->metric('ed_boarding', 'ED Boarding', $boarding, 'pts', (string) $boarding,
                4, '<4', 'warning', [6, 5, 5, 4, 4], 'down', true, '/dashboard/emergency',
                'Admitted ED patients awaiting an inpatient bed. Target boarding time <4h.'),
            $this->metric('dc_ready', 'Discharges Ready', 9, 'pts', '9',
                null, 'DBN 25%', 'success', [5, 6, 7, 8, 9], 'up', false, '/rtdc/bed-placement',
                'Patients with completed discharge orders awaiting departure.'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function unitCensus(): array
    {
        $rows = [
            ['unitId' => 1, 'name' => '5 East', 'type' => 'Med-Surg', 'staffed' => 30, 'occupied' => 27, 'blocked' => 1],
            ['unitId' => 2, 'name' => '6 West', 'type' => 'Med-Surg', 'staffed' => 28, 'occupied' => 26, 'blocked' => 0],
            ['unitId' => 3, 'name' => 'MICU', 'type' => 'ICU', 'staffed' => 16, 'occupied' => 15, 'blocked' => 1],
            ['unitId' => 4, 'name' => 'SICU', 'type' => 'ICU', 'staffed' => 14, 'occupied' => 11, 'blocked' => 0],
            ['unitId' => 5, 'name' => 'Telemetry', 'type' => 'Step-down', 'staffed' => 24, 'occupied' => 22, 'blocked' => 2],
            ['unitId' => 6, 'name' => 'PCU', 'type' => 'Step-down', 'staffed' => 20, 'occupied' => 16, 'blocked' => 0],
        ];

        return array_map(function (array $r): array {
            $available = max(0, $r['staffed'] - $r['occupied'] - $r['blocked']);
            $occPct = (int) round(($r['occupied'] / max(1, $r['staffed'])) * 100);
            $status = $occPct >= 92 ? 'critical' : ($occPct >= 85 ? 'warning' : 'success');
            return [
                'unitId' => $r['unitId'], 'name' => $r['name'], 'type' => $r['type'],
                'staffed' => $r['staffed'], 'occupied' => $r['occupied'], 'blocked' => $r['blocked'],
                'available' => $available, 'occupancyPct' => $occPct,
                'acuityAdjustedPct' => min(100, $occPct + 3), 'status' => $status,
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function capacityBand(array $units): array
    {
        $available = array_sum(array_column($units, 'available'));
        $blocked = array_sum(array_column($units, 'blocked'));
        return [
            'key' => 'capacity', 'title' => 'Capacity', 'summary' => '88% occupied house-wide',
            'drillHref' => '/rtdc/bed-tracking', 'drillLabel' => 'open RTDC',
            'metrics' => [
                $this->metric('available_beds', 'Available', $available, 'beds', (string) $available,
                    null, null, 'success', [10, 12, 13, 14, (int) $available], 'up', false, '/rtdc/bed-tracking',
                    'Staffed, unoccupied, unblocked beds available now.'),
                $this->metric('blocked_beds', 'Blocked', $blocked, 'beds', (string) $blocked,
                    0, '0', $blocked > 4 ? 'warning' : 'success', [3, 4, 4, 5, (int) $blocked], 'flat', true,
                    '/rtdc/bed-tracking', 'Beds offline due to staffing, environmental, or isolation barriers.'),
                $this->metric('acuity_adjusted', 'Acuity-Adjusted', 92, '%', '92%',
                    null, null, 'warning', [88, 90, 91, 92, 92], 'up', true, '/rtdc/bed-tracking',
                    'Capacity adjusted for current patient acuity mix.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function flowBand(): array
    {
        return [
            'key' => 'flow', 'title' => 'Flow', 'summary' => 'ED · Inpatient · OR throughput',
            'drillHref' => '/dashboard/emergency', 'drillLabel' => 'open ED',
            'metrics' => [],
            'subgroups' => [
                ['key' => 'ed', 'label' => 'Emergency', 'metrics' => [
                    $this->metric('ed_d2p', 'Door-to-Provider', 17, 'min', '17m', 20, '<20m', 'success',
                        [22, 20, 19, 18, 17], 'down', true, '/dashboard/emergency', 'Median arrival to provider evaluation.'),
                    $this->metric('ed_lwbs', 'LWBS', 0.9, '%', '0.9%', 2, '<2%', 'success',
                        [1.4, 1.2, 1.1, 1.0, 0.9], 'down', true, '/dashboard/emergency', 'Left without being seen.'),
                    $this->metric('ed_los', 'ED LOS (disch)', 138, 'min', '138m', 150, '<150m', 'success',
                        [150, 145, 142, 140, 138], 'down', true, '/dashboard/emergency', 'Median ED length of stay, discharged patients.'),
                ]],
                ['key' => 'ip', 'label' => 'Inpatient', 'metrics' => [
                    $this->metric('adm_to_bed', 'Admit→Bed', 47, 'min', '47m', 60, '<60m', 'success',
                        [62, 58, 52, 49, 47], 'down', true, '/rtdc/bed-placement', 'Admit decision to bed assigned.'),
                    $this->metric('dbn', 'Discharge by Noon', 18, '%', '18%', 25, '25%', 'warning',
                        [10, 13, 15, 17, 18], 'up', false, '/rtdc/bed-placement', 'Percent of discharges completed before noon.'),
                ]],
                ['key' => 'or', 'label' => 'Operating Room', 'metrics' => [
                    $this->metric('fcots', 'First-Case On-Time', 82, '%', '82%', 85, '≥85%', 'warning',
                        [76, 78, 80, 81, 82], 'up', false, '/dashboard/perioperative', 'First cases starting on time (15-min grace).'),
                    $this->metric('block_util', 'Block Utilization', 76, '%', '76%', 80, '80%', 'warning',
                        [71, 73, 74, 75, 76], 'up', false, '/dashboard/perioperative', 'Used block minutes / allocated block minutes.'),
                    $this->metric('turnover', 'Turnover', 31, 'min', '31m', 25, '<25m', 'warning',
                        [35, 34, 33, 32, 31], 'down', true, '/dashboard/perioperative', 'Median room turnover time.'),
                    $this->metric('cancellations', 'Same-Day Cxl', 2, 'cases', '2', null, null, 'success',
                        [4, 3, 3, 2, 2], 'down', true, '/dashboard/perioperative', 'Day-of-surgery cancellations.'),
                ]],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function outcomesBand(): array
    {
        return [
            'key' => 'outcomes', 'title' => 'Outcomes', 'summary' => 'Safety & efficiency results',
            'drillHref' => '/dashboard/improvement', 'drillLabel' => 'open Improvement',
            'metrics' => [
                $this->metric('readmission', '30-Day Readmission', 12.1, '%', '12.1%', 11, '<11%', 'warning',
                    [13.0, 12.7, 12.4, 12.2, 12.1], 'down', true, '/dashboard/improvement', '30-day all-cause readmission rate.'),
                $this->metric('los_gmlos', 'LOS / GMLOS', 1.10, 'x', '1.10', 1.0, '1.00', 'warning',
                    [1.18, 1.15, 1.13, 1.11, 1.10], 'down', true, '/dashboard/improvement', 'Observed LOS vs geometric-mean LOS.'),
                $this->metric('excess_days', 'Excess Bed-Days', 142, 'days', '142', null, null, 'warning',
                    [190, 175, 160, 150, 142], 'down', true, '/dashboard/improvement', 'Avoidable bed-days vs GMLOS this period.'),
                $this->metric('diversion', 'Diversion Hours', 0, 'h', '0h', 0, '0h', 'success',
                    [2, 1, 1, 0, 0], 'down', true, '/dashboard/improvement', 'Capacity-related ED diversion hours.'),
                $this->metric('pdsa_active', 'Active PDSA', 5, 'cycles', '5', null, null, 'info',
                    [3, 4, 4, 5, 5], 'up', false, '/dashboard/improvement', 'Improvement cycles in progress.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function forecastBand(int $netBeds): array
    {
        return [
            'key' => 'forecast', 'title' => 'Forecast', 'summary' => 'Next 24–48h projection',
            'drillHref' => '/rtdc/predictions/demand', 'drillLabel' => 'open Predictions',
            'metrics' => [
                $this->metric('pred_discharges', 'Discharges 24h', 22, 'pts', '22', null, null, 'info',
                    [18, 19, 20, 21, 22], 'up', false, '/rtdc/predictions/discharge', 'Predicted discharges in the next 24h.'),
                $this->metric('pred_arrivals', 'ED Arrivals 24h', 60, 'pts', '60', null, null, 'info',
                    [54, 56, 58, 59, 60], 'up', false, '/rtdc/predictions/demand', 'Predicted ED arrivals in the next 24h.'),
                $this->metric('net_beds_fc', 'Net Beds (proj)', $netBeds, 'beds', (string) $netBeds,
                    0, '≥0', 'critical', [1, 0, -1, -2, $netBeds], 'down', false, '/rtdc/predictions/demand',
                    'Projected supply minus demand at the next demand peak.'),
                $this->metric('surge_prob', 'Surge Probability', 38, '%', '38%', null, null, 'warning',
                    [25, 30, 33, 36, 38], 'up', true, '/rtdc/predictions/demand', 'Modeled probability of a surge event in 24h.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function forecastDetail(int $netBeds, array $units): array
    {
        $curve = [];
        $base = 88;
        for ($h = 0; $h <= 24; $h += 2) {
            $occ = $base + (int) round(4 * sin($h / 4));
            $curve[] = ['hourOffset' => $h, 'occupancyPct' => $occ, 'lowerPct' => $occ - 3, 'upperPct' => $occ + 3];
        }
        return [
            'predictedDischarges24h' => 22, 'predictedDischarges48h' => 41,
            'predictedEdArrivals' => 60, 'predictedAdmissions' => 18,
            'netBedPosition' => $netBeds, 'surgeProbabilityPct' => 38,
            'occupancyCurve' => $curve,
            'netBedByUnit' => array_map(
                fn (array $u): array => ['unitId' => $u['unitId'], 'name' => $u['name'],
                    'net' => $u['available'] - ($u['blocked'] + 1)],
                $units,
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function objectives(): array
    {
        return [
            ['key' => 'flow', 'title' => 'Improve access & flow', 'keyResults' => [
                ['label' => 'ED boarding', 'current' => 168, 'target' => 120, 'baseline' => 192,
                 'progressPct' => 33, 'status' => 'warning', 'display' => '168→<120 min'],
                ['label' => 'Discharge by noon', 'current' => 18, 'target' => 25, 'baseline' => 10,
                 'progressPct' => 53, 'status' => 'warning', 'display' => '18%→25%'],
            ]],
            ['key' => 'or', 'title' => 'Maximize surgical throughput', 'keyResults' => [
                ['label' => 'First-case on-time', 'current' => 82, 'target' => 85, 'baseline' => 76,
                 'progressPct' => 67, 'status' => 'warning', 'display' => '82%→85%'],
                ['label' => 'Block utilization', 'current' => 76, 'target' => 80, 'baseline' => 71,
                 'progressPct' => 56, 'status' => 'warning', 'display' => '76%→80%'],
            ]],
            ['key' => 'beddays', 'title' => 'Eliminate avoidable bed-days', 'keyResults' => [
                ['label' => 'LOS / GMLOS', 'current' => 110, 'target' => 100, 'baseline' => 118,
                 'progressPct' => 44, 'status' => 'warning', 'display' => '1.10→1.00'],
            ]],
        ];
    }
}
```

> Note: the display characters in the strings above (`≤`, `→`, `·`, `≥`, `▲`, `▼`) are literal UTF-8 — write them as-is (PHP source is UTF-8). Do not convert them to escape sequences.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CommandCenterDataServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint app/Services/CommandCenterDataService.php tests/Feature/CommandCenterDataServiceTest.php
git add app/Services/CommandCenterDataService.php tests/Feature/CommandCenterDataServiceTest.php
git commit -m "feat(dashboard): command center representative-data service"
```

---

## Task 3: Controller + route repoint

**Files:**
- Create: `app/Http/Controllers/CommandCenterController.php`
- Modify: `routes/web.php` (lines 50-55 closure → controller; add import near line 5)
- Test: `tests/Feature/CommandCenterControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/CommandCenterControllerTest.php
namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommandCenterControllerTest extends TestCase
{
    public function test_dashboard_renders_command_center_with_payload(): void
    {
        $user = User::factory()->make(['must_change_password' => false]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/CommandCenter')
                ->has('data.strain')
                ->has('data.heroMetrics')
                ->has('data.capacity')
                ->has('data.flow')
                ->has('data.forecast'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CommandCenterControllerTest`
Expected: FAIL — currently `/dashboard` renders `Home/Home`, so `->component('Dashboard/CommandCenter')` fails.

- [ ] **Step 3a: Write the controller**

```php
<?php
// app/Http/Controllers/CommandCenterController.php
namespace App\Http\Controllers;

use App\Services\CommandCenterDataService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CommandCenterController extends Controller
{
    public function __construct(
        private readonly CommandCenterDataService $dataService,
    ) {}

    /**
     * Display the Hospital Operations Command Center (main dashboard).
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Dashboard/CommandCenter', [
            'data' => $this->dataService->build(),
        ]);
    }
}
```

- [ ] **Step 3b: Repoint the route**

In `routes/web.php`, add the import after the other controller imports (near line 5):

```php
use App\Http\Controllers\CommandCenterController;
```

Replace the existing `/dashboard` closure (currently lines 50-55):

```php
    Route::get('/dashboard', function(Request $request) {
        $request->session()->put('workflow', 'superuser');
        return Inertia::render('Home/Home', [
            'workflow' => 'superuser'
        ]);
    })->name('dashboard');
```

with:

```php
    Route::get('/dashboard', [CommandCenterController::class, 'index'])->name('dashboard');
```

Leave `/home` (line 33) and `/dashboard/perioperative` (line 47) unchanged — the superuser landing and the perioperative dashboard remain reachable.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CommandCenterControllerTest`
Expected: PASS (1 test).

> If the test fails because authenticated requests are redirected by a forced-password-change guard, confirm the `must_change_password => false` user; the auth system redirects only at login, not per-request, so `/dashboard` should return 200.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint app/Http/Controllers/CommandCenterController.php tests/Feature/CommandCenterControllerTest.php routes/web.php
git add app/Http/Controllers/CommandCenterController.php tests/Feature/CommandCenterControllerTest.php routes/web.php
git commit -m "feat(dashboard): point /dashboard at command center controller"
```

---

## Task 4: `status.ts` + `KpiTile`

**Files:**
- Create: `resources/js/Components/CommandCenter/status.ts`
- Create: `resources/js/Components/CommandCenter/KpiTile.tsx`
- Test: `tests/js/commandCenter/KpiTile.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/KpiTile.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { KpiTile } from '@/Components/CommandCenter/KpiTile';
import type { KpiMetric } from '@/types/commandCenter';

const base: KpiMetric = {
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%',
  target: 85, targetDisplay: '≤85%', status: 'warning',
  trajectory: { points: [82, 84, 88], direction: 'up', goodWhenDown: true },
  drillHref: '/rtdc/bed-tracking', definition: 'Staffed occupancy.',
};

describe('KpiTile', () => {
  it('renders label, value, target and definition', () => {
    render(<KpiTile metric={base} />);
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.getByText('88%')).toBeInTheDocument();
    expect(screen.getByText(/Target/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Definition: Staffed occupancy/)).toBeInTheDocument();
  });

  it('wraps in a drill link when drillHref is set', () => {
    render(<KpiTile metric={base} />);
    const link = screen.getByTestId('kpi-occupancy').closest('a');
    expect(link).toHaveAttribute('href', '/rtdc/bed-tracking');
  });

  it('renders without a link when drillHref is null', () => {
    render(<KpiTile metric={{ ...base, key: 'x', drillHref: null }} />);
    expect(screen.getByTestId('kpi-x').closest('a')).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/KpiTile.test.tsx`
Expected: FAIL — cannot find `@/Components/CommandCenter/KpiTile`.

- [ ] **Step 3a: Write `status.ts`**

```ts
// resources/js/Components/CommandCenter/status.ts
import type { StatusLevel } from '@/types/commandCenter';

export const STATUS_VAR: Record<StatusLevel, string> = {
  critical: 'var(--critical)',
  warning: 'var(--warning)',
  success: 'var(--success)',
  info: 'var(--info)',
  neutral: 'var(--text-muted)',
};
```

- [ ] **Step 3b: Write `KpiTile.tsx`**

```tsx
// resources/js/Components/CommandCenter/KpiTile.tsx
import { Link } from '@inertiajs/react';
import type { KpiMetric } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

function Sparkline({ points, color }: { points: number[]; color: string }) {
  if (points.length < 2) return null;
  const w = 56, h = 16;
  const min = Math.min(...points);
  const span = Math.max(...points) - min || 1;
  const d = points
    .map((p, i) => {
      const x = (i / (points.length - 1)) * w;
      const y = h - ((p - min) / span) * h;
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden="true">
      <path d={d} fill="none" stroke={color} strokeWidth={1.5} />
    </svg>
  );
}

export function KpiTile({ metric }: { metric: KpiMetric }) {
  const color = STATUS_VAR[metric.status];
  const arrow = metric.trajectory
    ? metric.trajectory.direction === 'up' ? '▲'
      : metric.trajectory.direction === 'down' ? '▼' : '▬'
    : null;

  const body = (
    <div className="flex h-full flex-col gap-1 rounded-md p-3"
         style={{ background: 'var(--surface-raised)', borderLeft: `3px solid ${color}` }}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
          {metric.label}
        </span>
        <button type="button" title={metric.definition}
                aria-label={`Definition: ${metric.definition}`}
                className="text-xs leading-none" style={{ color: 'var(--text-ghost)' }}>
          {'ⓘ'}
        </button>
      </div>
      <div className="flex items-end justify-between gap-2">
        <span className="text-2xl font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>
          {metric.display}
        </span>
        {metric.trajectory && (
          <span className="flex items-center gap-1 text-xs" style={{ color }}>
            <Sparkline points={metric.trajectory.points} color={color} />
            <span aria-hidden="true">{arrow}</span>
          </span>
        )}
      </div>
      {metric.targetDisplay && (
        <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>
          Target {metric.targetDisplay}
        </span>
      )}
    </div>
  );

  if (metric.drillHref) {
    return (
      <Link href={metric.drillHref} className="block h-full" data-testid={`kpi-${metric.key}`}>
        {body}
      </Link>
    );
  }
  return <div data-testid={`kpi-${metric.key}`} className="h-full">{body}</div>;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/KpiTile.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/status.ts resources/js/Components/CommandCenter/KpiTile.tsx tests/js/commandCenter/KpiTile.test.tsx
git commit -m "feat(dashboard): KpiTile + status token map"
```

---

## Task 5: `StrainIndex`

**Files:**
- Create: `resources/js/Components/CommandCenter/StrainIndex.tsx`
- Test: `tests/js/commandCenter/StrainIndex.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/StrainIndex.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StrainIndex } from '@/Components/CommandCenter/StrainIndex';
import type { StrainState } from '@/types/commandCenter';

const strain: StrainState = {
  level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
  drivers: [
    { label: 'Occupancy', value: '88%', status: 'warning' },
    { label: 'ED boarding', value: '4', status: 'warning' },
  ],
  updatedAtIso: '2026-06-22T12:00:00Z',
};

describe('StrainIndex', () => {
  it('renders the surge label and drivers', () => {
    render(<StrainIndex strain={strain} />);
    expect(screen.getByText('Surge Level 2')).toBeInTheDocument();
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.getByText('88%')).toBeInTheDocument();
  });

  it('exposes an accessible status label', () => {
    render(<StrainIndex strain={strain} />);
    expect(screen.getByRole('status')).toHaveAttribute('aria-label', expect.stringContaining('Surge Level 2'));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/StrainIndex.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `StrainIndex.tsx`**

```tsx
// resources/js/Components/CommandCenter/StrainIndex.tsx
import type { StrainState } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function StrainIndex({ strain }: { strain: StrainState }) {
  const color = STATUS_VAR[strain.status];
  const trend = strain.level > strain.previousLevel ? '▲'
    : strain.level < strain.previousLevel ? '▼' : '▬';

  return (
    <div role="status" aria-label={`${strain.label}, status ${strain.status}`}
         className="flex h-full flex-col gap-2 rounded-lg p-4"
         style={{ background: 'var(--surface-overlay)', border: `1px solid ${color}` }}>
      <span className="text-xs uppercase tracking-widest" style={{ color: 'var(--text-muted)' }}>
        House Status
      </span>
      <div className="flex items-baseline gap-2">
        <span className="text-4xl font-semibold leading-none" style={{ color }}>{strain.label}</span>
        <span className="text-sm" style={{ color }} aria-hidden="true">
          {trend} from L{strain.previousLevel}
        </span>
      </div>
      <ul className="mt-1 flex flex-col gap-1">
        {strain.drivers.map((d) => (
          <li key={d.label} className="flex items-center justify-between text-xs">
            <span style={{ color: 'var(--text-secondary)' }}>{d.label}</span>
            <span className="tabular-nums" style={{ color: STATUS_VAR[d.status] }}>{d.value}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/StrainIndex.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/StrainIndex.tsx tests/js/commandCenter/StrainIndex.test.tsx
git commit -m "feat(dashboard): StrainIndex hero cell"
```

---

## Task 6: `Band`

**Files:**
- Create: `resources/js/Components/CommandCenter/Band.tsx`
- Test: `tests/js/commandCenter/Band.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/Band.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Band } from '@/Components/CommandCenter/Band';
import type { BandData, KpiMetric } from '@/types/commandCenter';

const metric = (key: string, label: string): KpiMetric => ({
  key, label, value: 1, unit: '', display: '1', target: null, targetDisplay: null,
  status: 'success', trajectory: null, drillHref: null, definition: `${label} def`,
});

const flat: BandData = {
  key: 'capacity', title: 'Capacity', summary: '88% occupied',
  drillHref: '/rtdc/bed-tracking', drillLabel: 'open RTDC',
  metrics: [metric('available_beds', 'Available'), metric('blocked_beds', 'Blocked')],
};

const grouped: BandData = {
  key: 'flow', title: 'Flow', summary: 'ED / IP / OR', drillHref: '/dashboard/emergency',
  drillLabel: 'open ED', metrics: [],
  subgroups: [
    { key: 'ed', label: 'Emergency', metrics: [metric('ed_d2p', 'Door-to-Provider')] },
    { key: 'or', label: 'Operating Room', metrics: [metric('fcots', 'First-Case On-Time')] },
  ],
};

describe('Band', () => {
  it('renders a flat band header, summary, drill link, and tiles', () => {
    render(<Band band={flat} />);
    expect(screen.getByRole('heading', { name: 'Capacity' })).toBeInTheDocument();
    expect(screen.getByText('88% occupied')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open RTDC/ })).toHaveAttribute('href', '/rtdc/bed-tracking');
    expect(screen.getByText('Available')).toBeInTheDocument();
    expect(screen.getByText('Blocked')).toBeInTheDocument();
  });

  it('renders subgroup labels and their tiles', () => {
    render(<Band band={grouped} />);
    expect(screen.getByText('Emergency')).toBeInTheDocument();
    expect(screen.getByText('Operating Room')).toBeInTheDocument();
    expect(screen.getByText('Door-to-Provider')).toBeInTheDocument();
    expect(screen.getByText('First-Case On-Time')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/Band.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `Band.tsx`**

```tsx
// resources/js/Components/CommandCenter/Band.tsx
import { Link } from '@inertiajs/react';
import type { BandData, KpiMetric } from '@/types/commandCenter';
import { KpiTile } from './KpiTile';

const GRID = 'repeat(auto-fit, minmax(150px, 1fr))';

function TileGrid({ metrics }: { metrics: KpiMetric[] }) {
  return (
    <div className="grid gap-2" style={{ gridTemplateColumns: GRID }}>
      {metrics.map((m) => <KpiTile key={m.key} metric={m} />)}
    </div>
  );
}

export function Band({ band }: { band: BandData }) {
  return (
    <section aria-label={band.title} className="flex flex-col gap-2">
      <header className="flex items-center justify-between gap-3 border-b pb-1"
              style={{ borderColor: 'var(--surface-elevated)' }}>
        <div className="flex items-baseline gap-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--text-primary)' }}>
            {band.title}
          </h2>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{band.summary}</span>
        </div>
        <Link href={band.drillHref} className="whitespace-nowrap text-xs" style={{ color: 'var(--accent)' }}>
          {band.drillLabel} {'→'}
        </Link>
      </header>

      {band.subgroups ? (
        <div className="flex flex-col gap-2">
          {band.subgroups.map((g) => (
            <div key={g.key} className="flex flex-col gap-1">
              <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{g.label}</span>
              <TileGrid metrics={g.metrics} />
            </div>
          ))}
        </div>
      ) : (
        <TileGrid metrics={band.metrics} />
      )}
    </section>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/Band.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/Band.tsx tests/js/commandCenter/Band.test.tsx
git commit -m "feat(dashboard): Band (header + drill link + tile grid)"
```

---

## Task 7: `UnitHeatStrip`

**Files:**
- Create: `resources/js/Components/CommandCenter/UnitHeatStrip.tsx`
- Test: `tests/js/commandCenter/UnitHeatStrip.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/UnitHeatStrip.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { UnitHeatStrip } from '@/Components/CommandCenter/UnitHeatStrip';
import type { UnitCensus } from '@/types/commandCenter';

const units: UnitCensus[] = [
  { unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' },
  { unitId: 3, name: 'MICU', type: 'ICU', staffed: 16, occupied: 15, blocked: 1,
    available: 0, occupancyPct: 94, acuityAdjustedPct: 97, status: 'critical' },
];

describe('UnitHeatStrip', () => {
  it('renders each unit name and occupancy', () => {
    render(<UnitHeatStrip units={units} />);
    expect(screen.getByText('5 East')).toBeInTheDocument();
    expect(screen.getByText('90%')).toBeInTheDocument();
    expect(screen.getByText('MICU')).toBeInTheDocument();
    expect(screen.getByText('94%')).toBeInTheDocument();
  });

  it('has an accessible label', () => {
    render(<UnitHeatStrip units={units} />);
    expect(screen.getByLabelText('Unit census heat map')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/UnitHeatStrip.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `UnitHeatStrip.tsx`**

```tsx
// resources/js/Components/CommandCenter/UnitHeatStrip.tsx
import type { UnitCensus } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function UnitHeatStrip({ units }: { units: UnitCensus[] }) {
  return (
    <div aria-label="Unit census heat map" className="grid gap-1"
         style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(96px, 1fr))' }}>
      {units.map((u) => (
        <div key={u.unitId}
             title={`${u.name}: ${u.occupied}/${u.staffed} occupied, ${u.available} available, ${u.blocked} blocked`}
             className="flex flex-col gap-0.5 rounded p-2"
             style={{ background: 'var(--surface-raised)', borderTop: `3px solid ${STATUS_VAR[u.status]}` }}>
          <span className="truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{u.name}</span>
          <span className="text-lg font-semibold tabular-nums" style={{ color: STATUS_VAR[u.status] }}>
            {u.occupancyPct}%
          </span>
          <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {u.available} open {'·'} {u.blocked} blk
          </span>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/UnitHeatStrip.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/UnitHeatStrip.tsx tests/js/commandCenter/UnitHeatStrip.test.tsx
git commit -m "feat(dashboard): UnitHeatStrip census row"
```

---

## Task 8: `ForecastCurve`

**Files:**
- Create: `resources/js/Components/CommandCenter/ForecastCurve.tsx`
- Test: `tests/js/commandCenter/ForecastCurve.test.tsx`

> Recharts charts do not render meaningful geometry in jsdom (zero-size container), so the test asserts the **textual summary** and the chart's accessible wrapper label, not SVG paths.

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/ForecastCurve.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ForecastCurve } from '@/Components/CommandCenter/ForecastCurve';
import type { ForecastState } from '@/types/commandCenter';

const forecast: ForecastState = {
  predictedDischarges24h: 22, predictedDischarges48h: 41, predictedEdArrivals: 60,
  predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
  occupancyCurve: [
    { hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 },
    { hourOffset: 2, occupancyPct: 90, lowerPct: 87, upperPct: 93 },
  ],
  netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }],
};

describe('ForecastCurve', () => {
  it('renders the forecast summary numbers', () => {
    render(<ForecastCurve forecast={forecast} />);
    expect(screen.getByText(/Predicted discharges 24h/)).toBeInTheDocument();
    expect(screen.getByText('22')).toBeInTheDocument();
    expect(screen.getByText('-3')).toBeInTheDocument();
    expect(screen.getByText('38%')).toBeInTheDocument();
  });

  it('labels the chart region', () => {
    render(<ForecastCurve forecast={forecast} />);
    expect(screen.getByLabelText('24-hour occupancy forecast')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/ForecastCurve.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `ForecastCurve.tsx`**

```tsx
// resources/js/Components/CommandCenter/ForecastCurve.tsx
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { ForecastState } from '@/types/commandCenter';

export function ForecastCurve({ forecast }: { forecast: ForecastState }) {
  const netColor = forecast.netBedPosition < 0 ? 'var(--critical)' : 'var(--success)';
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap gap-4 text-xs" style={{ color: 'var(--text-secondary)' }}>
        <span>Predicted discharges 24h:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.predictedDischarges24h}</strong></span>
        <span>ED arrivals 24h:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.predictedEdArrivals}</strong></span>
        <span>Net bed position:{' '}
          <strong style={{ color: netColor }}>{forecast.netBedPosition}</strong></span>
        <span>Surge probability:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.surgeProbabilityPct}%</strong></span>
      </div>
      <div aria-label="24-hour occupancy forecast" style={{ width: '100%', height: 140 }}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={forecast.occupancyCurve}>
            <XAxis dataKey="hourOffset" tick={{ fontSize: 10 }} />
            <YAxis domain={[60, 100]} width={28} tick={{ fontSize: 10 }} />
            <Tooltip />
            <Area type="monotone" dataKey="upperPct" stroke="none" fill="var(--info)" fillOpacity={0.15} />
            <Area type="monotone" dataKey="occupancyPct" stroke="var(--info)" fill="var(--info)" fillOpacity={0.3} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/ForecastCurve.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/ForecastCurve.tsx tests/js/commandCenter/ForecastCurve.test.tsx
git commit -m "feat(dashboard): ForecastCurve (24h occupancy projection)"
```

---

## Task 9: `commandCenterStore` + `RoleSwitcher`

**Files:**
- Create: `resources/js/stores/commandCenterStore.ts`
- Create: `resources/js/Components/CommandCenter/RoleSwitcher.tsx`
- Test: `tests/js/commandCenter/RoleSwitcher.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/RoleSwitcher.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import { useCommandCenterStore } from '@/stores/commandCenterStore';

describe('RoleSwitcher', () => {
  beforeEach(() => {
    useCommandCenterStore.setState({ role: 'command', serviceLine: null });
  });

  it('marks the current role as selected', () => {
    render(<RoleSwitcher />);
    expect(screen.getByRole('tab', { name: 'Command' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'Executive' })).toHaveAttribute('aria-selected', 'false');
  });

  it('switches role on click and updates the store', () => {
    render(<RoleSwitcher />);
    fireEvent.click(screen.getByRole('tab', { name: 'Executive' }));
    expect(useCommandCenterStore.getState().role).toBe('executive');
    expect(screen.getByRole('tab', { name: 'Executive' })).toHaveAttribute('aria-selected', 'true');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/RoleSwitcher.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3a: Write `commandCenterStore.ts`**

```ts
// resources/js/stores/commandCenterStore.ts
import { create } from 'zustand';

export type CommandRole = 'command' | 'executive' | 'service-line';

interface CommandCenterState {
  role: CommandRole;
  serviceLine: string | null;
  setRole: (role: CommandRole) => void;
  setServiceLine: (line: string | null) => void;
}

export const useCommandCenterStore = create<CommandCenterState>((set) => ({
  role: 'command',
  serviceLine: null,
  setRole: (role) => set({ role }),
  setServiceLine: (serviceLine) => set({ serviceLine }),
}));
```

- [ ] **Step 3b: Write `RoleSwitcher.tsx`**

```tsx
// resources/js/Components/CommandCenter/RoleSwitcher.tsx
import { useCommandCenterStore, type CommandRole } from '@/stores/commandCenterStore';

const ROLES: { value: CommandRole; label: string }[] = [
  { value: 'command', label: 'Command' },
  { value: 'executive', label: 'Executive' },
  { value: 'service-line', label: 'Service-line' },
];

export function RoleSwitcher() {
  const role = useCommandCenterStore((s) => s.role);
  const setRole = useCommandCenterStore((s) => s.setRole);

  return (
    <div role="tablist" aria-label="Dashboard view" className="inline-flex rounded-md p-0.5"
         style={{ background: 'var(--surface-raised)' }}>
      {ROLES.map((r) => {
        const active = r.value === role;
        return (
          <button key={r.value} type="button" role="tab" aria-selected={active}
                  onClick={() => setRole(r.value)}
                  className="rounded px-3 py-1 text-xs"
                  style={{
                    background: active ? 'var(--surface-elevated)' : 'transparent',
                    color: active ? 'var(--text-primary)' : 'var(--text-muted)',
                  }}>
            {r.label}
          </button>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/RoleSwitcher.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/stores/commandCenterStore.ts resources/js/Components/CommandCenter/RoleSwitcher.tsx tests/js/commandCenter/RoleSwitcher.test.tsx
git commit -m "feat(dashboard): role switcher + command center store"
```

---

## Task 10: `OkrScoreboard`

**Files:**
- Create: `resources/js/Components/CommandCenter/OkrScoreboard.tsx`
- Test: `tests/js/commandCenter/OkrScoreboard.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/OkrScoreboard.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { OkrScoreboard } from '@/Components/CommandCenter/OkrScoreboard';
import type { Objective } from '@/types/commandCenter';

const objectives: Objective[] = [
  { key: 'flow', title: 'Improve access & flow', keyResults: [
    { label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' },
  ] },
];

describe('OkrScoreboard', () => {
  it('renders objective title and key-result display', () => {
    render(<OkrScoreboard objectives={objectives} />);
    expect(screen.getByRole('heading', { name: 'Improve access & flow' })).toBeInTheDocument();
    expect(screen.getByText('ED boarding')).toBeInTheDocument();
    expect(screen.getByText('168→<120 min')).toBeInTheDocument();
  });

  it('renders a progress bar reflecting progressPct', () => {
    render(<OkrScoreboard objectives={objectives} />);
    expect(screen.getByTestId('kr-progress-ED boarding')).toHaveStyle({ width: '33%' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/OkrScoreboard.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `OkrScoreboard.tsx`**

```tsx
// resources/js/Components/CommandCenter/OkrScoreboard.tsx
import type { Objective } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function OkrScoreboard({ objectives }: { objectives: Objective[] }) {
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
      {objectives.map((o) => (
        <div key={o.key} className="flex flex-col gap-2 rounded-lg p-3"
             style={{ background: 'var(--surface-overlay)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{o.title}</h3>
          <ul className="flex flex-col gap-2">
            {o.keyResults.map((kr) => {
              const pct = Math.max(0, Math.min(100, kr.progressPct));
              return (
                <li key={kr.label} className="flex flex-col gap-1">
                  <div className="flex items-center justify-between text-xs">
                    <span style={{ color: 'var(--text-secondary)' }}>{kr.label}</span>
                    <span className="tabular-nums" style={{ color: STATUS_VAR[kr.status] }}>{kr.display}</span>
                  </div>
                  <div className="h-1.5 w-full rounded-full" style={{ background: 'var(--surface-raised)' }}>
                    <div data-testid={`kr-progress-${kr.label}`} className="h-full rounded-full"
                         style={{ width: `${pct}%`, background: STATUS_VAR[kr.status] }} />
                  </div>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/OkrScoreboard.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/OkrScoreboard.tsx tests/js/commandCenter/OkrScoreboard.test.tsx
git commit -m "feat(dashboard): OKR scoreboard (executive mode)"
```

---

## Task 11: `HeroWall`

**Files:**
- Create: `resources/js/Components/CommandCenter/HeroWall.tsx`
- Test: `tests/js/commandCenter/HeroWall.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
// tests/js/commandCenter/HeroWall.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { HeroWall } from '@/Components/CommandCenter/HeroWall';
import type { StrainState, KpiMetric, Objective } from '@/types/commandCenter';

const strain: StrainState = {
  level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
  drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: 'x',
};
const heroMetrics: KpiMetric[] = [{
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%', target: 85,
  targetDisplay: '≤85%', status: 'warning', trajectory: null, drillHref: null, definition: 'd',
}];
const objectives: Objective[] = [{
  key: 'flow', title: 'Improve access & flow',
  keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
    status: 'warning', display: '168→<120 min' }],
}];

describe('HeroWall', () => {
  it('command mode shows strain + hero tiles', () => {
    render(<HeroWall role="command" strain={strain} heroMetrics={heroMetrics} objectives={objectives} />);
    expect(screen.getByRole('status')).toHaveAttribute('aria-label', expect.stringContaining('Surge Level 2'));
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.queryByLabelText('OKR scoreboard')).toBeNull();
  });

  it('executive mode shows the OKR scoreboard instead of strain', () => {
    render(<HeroWall role="executive" strain={strain} heroMetrics={heroMetrics} objectives={objectives} />);
    expect(screen.getByLabelText('OKR scoreboard')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Improve access & flow' })).toBeInTheDocument();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/HeroWall.test.tsx`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Write `HeroWall.tsx`**

```tsx
// resources/js/Components/CommandCenter/HeroWall.tsx
import type { KpiMetric, Objective, StrainState } from '@/types/commandCenter';
import type { CommandRole } from '@/stores/commandCenterStore';
import { StrainIndex } from './StrainIndex';
import { KpiTile } from './KpiTile';
import { OkrScoreboard } from './OkrScoreboard';

interface HeroWallProps {
  role: CommandRole;
  strain: StrainState;
  heroMetrics: KpiMetric[];
  objectives: Objective[];
}

export function HeroWall({ role, strain, heroMetrics, objectives }: HeroWallProps) {
  if (role === 'executive') {
    return (
      <div aria-label="OKR scoreboard">
        <OkrScoreboard objectives={objectives} />
      </div>
    );
  }

  return (
    <div className="grid gap-2"
         style={{ gridTemplateColumns: 'minmax(220px, 1.4fr) repeat(auto-fit, minmax(150px, 1fr))' }}>
      <StrainIndex strain={strain} />
      {heroMetrics.map((m) => <KpiTile key={m.key} metric={m} />)}
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/HeroWall.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/CommandCenter/HeroWall.tsx tests/js/commandCenter/HeroWall.test.tsx
git commit -m "feat(dashboard): HeroWall bento (strain + hero tiles / OKR board)"
```

---

## Task 12: Test fixture + `CommandCenterView`

**Files:**
- Create: `tests/js/commandCenter/fixture.ts`
- Create: `resources/js/Components/CommandCenter/CommandCenterView.tsx`
- Test: `tests/js/commandCenter/CommandCenterView.test.tsx`

- [ ] **Step 1: Write the fixture**

```ts
// tests/js/commandCenter/fixture.ts
import type { CommandCenterData, KpiMetric } from '@/types/commandCenter';

const m = (key: string, label: string, drillHref: string | null = null): KpiMetric => ({
  key, label, value: 1, unit: '', display: '1', target: null, targetDisplay: null,
  status: 'success', trajectory: { points: [1, 2, 3], direction: 'up', goodWhenDown: false },
  drillHref, definition: `${label} def`,
});

export const commandCenterFixture: CommandCenterData = {
  generatedAtIso: '2026-06-22T12:00:00Z',
  strain: { level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
    drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: '2026-06-22T12:00:00Z' },
  heroMetrics: [m('occupancy', 'Occupancy', '/rtdc/bed-tracking'), m('net_beds', 'Net Bed Position')],
  capacity: { key: 'capacity', title: 'Capacity', summary: 's', drillHref: '/rtdc/bed-tracking',
    drillLabel: 'open RTDC', metrics: [m('available_beds', 'Available')] },
  flow: { key: 'flow', title: 'Flow', summary: 's', drillHref: '/dashboard/emergency', drillLabel: 'open ED',
    metrics: [], subgroups: [{ key: 'ed', label: 'Emergency', metrics: [m('ed_d2p', 'Door-to-Provider')] }] },
  outcomes: { key: 'outcomes', title: 'Outcomes', summary: 's', drillHref: '/dashboard/improvement',
    drillLabel: 'open Improvement', metrics: [m('readmission', '30-Day Readmission')] },
  forecast: { key: 'forecast', title: 'Forecast', summary: 's', drillHref: '/rtdc/predictions/demand',
    drillLabel: 'open Predictions', metrics: [m('pred_discharges', 'Discharges 24h')] },
  forecastDetail: { predictedDischarges24h: 22, predictedDischarges48h: 41, predictedEdArrivals: 60,
    predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
    occupancyCurve: [{ hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 }],
    netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }] },
  unitCensus: [{ unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' }],
  objectives: [{ key: 'flow', title: 'Improve access & flow',
    keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' }] }],
};
```

- [ ] **Step 2: Write the failing test**

```tsx
// tests/js/commandCenter/CommandCenterView.test.tsx
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import { commandCenterFixture } from './fixture';

describe('CommandCenterView', () => {
  beforeEach(() => {
    useCommandCenterStore.setState({ role: 'command', serviceLine: null });
  });

  it('renders all four band titles and the role switcher', () => {
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} refreshedLabel="just now" />);
    expect(screen.getByRole('heading', { name: 'Capacity' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Flow' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Outcomes' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Forecast' })).toBeInTheDocument();
    expect(screen.getByRole('tablist', { name: 'Dashboard view' })).toBeInTheDocument();
  });

  it('calls onRefresh when the refresh button is clicked', () => {
    const onRefresh = vi.fn();
    render(<CommandCenterView data={commandCenterFixture} onRefresh={onRefresh} refreshedLabel="just now" />);
    fireEvent.click(screen.getByRole('button', { name: /refresh/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('shows the OKR scoreboard when role is executive', () => {
    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} refreshedLabel="just now" />);
    expect(screen.getByLabelText('OKR scoreboard')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npx vitest run tests/js/commandCenter/CommandCenterView.test.tsx`
Expected: FAIL — cannot find `@/Components/CommandCenter/CommandCenterView`.

- [ ] **Step 4: Write `CommandCenterView.tsx`**

```tsx
// resources/js/Components/CommandCenter/CommandCenterView.tsx
import type { CommandCenterData } from '@/types/commandCenter';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import { HeroWall } from './HeroWall';
import { Band } from './Band';
import { UnitHeatStrip } from './UnitHeatStrip';
import { ForecastCurve } from './ForecastCurve';
import { RoleSwitcher } from './RoleSwitcher';

interface CommandCenterViewProps {
  data: CommandCenterData;
  onRefresh: () => void;
  refreshedLabel: string;
}

export function CommandCenterView({ data, onRefresh, refreshedLabel }: CommandCenterViewProps) {
  const role = useCommandCenterStore((s) => s.role);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <RoleSwitcher />
        <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <span>Updated {refreshedLabel}</span>
          <button type="button" onClick={onRefresh} aria-label="Refresh data"
                  className="rounded px-2 py-1"
                  style={{ background: 'var(--surface-raised)', color: 'var(--text-secondary)' }}>
            {'⟳'} Refresh
          </button>
        </div>
      </div>

      <HeroWall role={role} strain={data.strain} heroMetrics={data.heroMetrics} objectives={data.objectives} />

      <div className="flex flex-col gap-2">
        <Band band={data.capacity} />
        <UnitHeatStrip units={data.unitCensus} />
      </div>
      <Band band={data.flow} />
      <Band band={data.outcomes} />
      <div className="flex flex-col gap-2">
        <Band band={data.forecast} />
        <ForecastCurve forecast={data.forecastDetail} />
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run tests/js/commandCenter/CommandCenterView.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add tests/js/commandCenter/fixture.ts resources/js/Components/CommandCenter/CommandCenterView.tsx tests/js/commandCenter/CommandCenterView.test.tsx
git commit -m "feat(dashboard): CommandCenterView composition + test fixture"
```

---

## Task 13: Inertia page (parse + refresh + layout)

**Files:**
- Create: `resources/js/Pages/Dashboard/CommandCenter.tsx`

> No new automated test here (the layout pulls in the full nav shell, which is heavy in jsdom; the composition is already covered by `CommandCenterView.test.tsx` and the controller feature test). This task is verified by the build + a manual smoke check in Task 14.

- [ ] **Step 1: Write `CommandCenter.tsx`**

```tsx
// resources/js/Pages/Dashboard/CommandCenter.tsx
import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { parseCommandCenterData } from '@/types/commandCenter';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';

const REFRESH_MS = 45_000;

export default function CommandCenter({ data }: { data: unknown }) {
  const cc = useMemo(() => parseCommandCenterData(data), [data]);
  const [refreshedLabel, setRefreshedLabel] = useState('just now');

  // Periodic background refresh of the payload only.
  useEffect(() => {
    const id = setInterval(() => {
      router.reload({ only: ['data'], preserveScroll: true });
    }, REFRESH_MS);
    return () => clearInterval(id);
  }, []);

  // Reset the freshness label whenever new data arrives.
  useEffect(() => {
    setRefreshedLabel('just now');
    const id = setInterval(() => setRefreshedLabel('moments ago'), 15_000);
    return () => clearInterval(id);
  }, [cc.generatedAtIso]);

  const handleRefresh = () => router.reload({ only: ['data'], preserveScroll: true });

  return (
    <DashboardLayout>
      <Head title="Operations Command Center - ZephyrusOR" />
      <PageContentLayout
        title="Hospital Operations Command Center"
        subtitle="House-wide demand, capacity, flow & forecast"
      >
        <CommandCenterView data={cc} onRefresh={handleRefresh} refreshedLabel={refreshedLabel} />
      </PageContentLayout>
    </DashboardLayout>
  );
}
```

- [ ] **Step 2: Type-check**

Run: `npx tsc --noEmit`
Expected: no errors. (If `DashboardLayout` / `PageContentLayout` have no type declarations, the `@/` imports still resolve as untyped modules — acceptable; do not convert those files in this task.)

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Dashboard/CommandCenter.tsx
git commit -m "feat(dashboard): command center Inertia page (parse + auto-refresh)"
```

---

## Task 14: Full verification & finish

**Files:** none (verification only)

- [ ] **Step 1: Run the full JS test suite**

Run: `npm test`
Expected: PASS — all `commandCenter/*` tests plus the pre-existing suites green.

- [ ] **Step 2: Type-check**

Run: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Production build (stricter than tsc)**

Run: `npx vite build`
Expected: build succeeds; no `UNRESOLVED_IMPORT` and no type/transform errors. Confirm `Pages/Dashboard/CommandCenter.tsx` is emitted in the manifest.

- [ ] **Step 4: Run the PHP tests**

Run: `php artisan test --filter=CommandCenter`
Expected: PASS — `CommandCenterDataServiceTest` (5) and `CommandCenterControllerTest` (1).

- [ ] **Step 5: Pint**

Run: `vendor/bin/pint app/Services/CommandCenterDataService.php app/Http/Controllers/CommandCenterController.php routes/web.php`
Expected: clean (no style violations) — fix and re-run if any.

- [ ] **Step 6: Manual smoke check**

Start the app (`composer dev` or the project's usual dev command), log in, and open `/dashboard`. Confirm:
- The command wall (strain cell + hero tiles) renders above the four bands.
- The role switcher toggles Command → Executive (OKR scoreboard appears) → Service-line.
- Band drill links navigate (Capacity → `/rtdc/bed-tracking`, Flow → `/dashboard/emergency`, Outcomes → `/dashboard/improvement`, Forecast → `/rtdc/predictions/demand`).
- `/dashboard/perioperative` and `/home` still render their existing pages.

- [ ] **Step 7: Final commit (if any verification fixes were made)**

```bash
git add -A
git commit -m "test(dashboard): verify command center (tsc, vite build, vitest, pint, php)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** four-band layout (Tasks 6,12), command wall/bento (Task 11), OKR-aware tiles (Task 4 + service), role switcher (Task 9), drill-down map (service `drillHref` + Band/KpiTile links), representative-data seam (Task 2), perioperative preservation (Task 3), density tactics (uniform in-band grids, sparklines, inline-style tokens), refresh model (Task 13), tests (every component + service + controller), tsc+vite build (Task 14). All spec sections map to a task.
- **No placeholders:** every code step contains complete code; unicode escapes are flagged for literal substitution in the one PHP file.
- **Type consistency:** the Zod-inferred types in `commandCenter.ts` are the single contract; the PHP service emits matching camelCase keys (validated structurally by `CommandCenterDataServiceTest` and at runtime by `parseCommandCenterData` in the page); component prop names (`metric`, `band`, `strain`, `units`, `forecast`, `objectives`, `role`, `data`, `onRefresh`, `refreshedLabel`) are used identically across definitions, tests, and call sites.
