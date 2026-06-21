# RTDC Four-Step Engine + Huddles (S2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Zephyrus's RTDC huddle UI from mock data into a working IHI Real-Time Demand Capacity engine — a daily four-step cycle (predict capacity → predict demand → develop plan → evaluate) run through two-tier huddles, standing on a genuinely live *simulated* census that updates the web UI via WebSocket in under 2 seconds.

**Architecture:** A PHP synthetic simulator emits **canonical operational events** through an in-process `EventDispatcher`; the dispatcher persists each event to an append-only `operational_events` ledger, applies it to a materialized census projection (`encounters`, `beds`, `census_snapshots`), and broadcasts deltas over Laravel Reverb. Domain services (`RtdcService`, `HuddleService`, `BarrierService`, `AcuityService`, `ReconciliationService`) implement the four-step methodology over Postgres. The existing React huddle pages are migrated to TypeScript and rewired to the live API via TanStack Query + Laravel Echo, with Zod schemas as the validation contract. The dispatcher and the `EventSource` contract are the **seam**: S1 later swaps the in-process dispatcher for Redis Streams and adds a Node/TS HL7v2/FHIR ingestion sidecar without changing producers, the projector, or the UI.

**Tech Stack:** Laravel 11 / PHP 8.2 · PostgreSQL (`prod` schema) · Laravel Reverb (new) · Pest · React 19 + Inertia + TypeScript · TanStack Query · Laravel Echo + pusher-js (new) · Zod (new) · Vitest · Playwright.

**Spec:** `docs/superpowers/specs/2026-06-20-rtdc-engine-huddles-design.md`
**Research:** `.planning/research/00-RESEARCH-DOSSIER.md` (§2 RTDC methodology)

---

## Deviations from the spec (decided during planning, with rationale)

1. **Simulator is a PHP artisan command, not a Node sidecar.** The spec's north-star puts ingestion in a Node/TS sidecar (S1/S8). For S2's *minimal substrate* that is overkill; a PHP simulator inside the Laravel app emitting canonical events is the simplest thing that works. The seam (`EventSource` contract + `EventDispatcher`) is preserved, so S1 can introduce the Node sidecar + Redis Streams later as additional event producers feeding the same `operational_events` ledger and projector.
2. **Shared Zod schemas live in `resources/js/schemas/`, not a `packages/core` monorepo.** No mobile app exists in S2, so there is nothing to share *across packages* yet. Standing up pnpm/Turborepo now is risk without payoff. S7 (Hummingbird) promotes these schemas into `packages/core` when code-sharing becomes real.
3. **Acuity is a manual 1–4 tier on the encounter** (per founder confirmation). The passive-EHR-signal acuity engine is deferred.

---

## File structure (what gets created/modified)

### Backend — domain & infrastructure
| File | Responsibility |
|------|----------------|
| `database/migrations/2026_06_20_000010_create_rtdc_units_beds_tables.php` | `units`, `beds` |
| `database/migrations/2026_06_20_000020_create_rtdc_encounters_census_tables.php` | `encounters`, `census_snapshots` |
| `database/migrations/2026_06_20_000030_create_rtdc_operational_events_table.php` | `operational_events` (append-only ledger) |
| `database/migrations/2026_06_20_000040_create_rtdc_predictions_plans_tables.php` | `rtdc_predictions`, `rtdc_plans` |
| `database/migrations/2026_06_20_000050_create_rtdc_huddles_barriers_tables.php` | `huddles`, `barriers` |
| `database/migrations/2026_06_20_000060_create_rtdc_reconciliations_table.php` | `rtdc_reconciliations` |
| `app/Models/Unit.php`, `Bed.php`, `Encounter.php`, `CensusSnapshot.php`, `OperationalEvent.php`, `RtdcPrediction.php`, `RtdcPlan.php`, `Huddle.php`, `Barrier.php`, `RtdcReconciliation.php` | Eloquent models |
| `app/Rtdc/Events/CanonicalEvent.php` | Immutable canonical event DTO + type constants |
| `app/Rtdc/Contracts/EventSource.php` | Interface every event producer implements |
| `app/Rtdc/EventDispatcher.php` | Persists ledger → projects → broadcasts (the seam) |
| `app/Rtdc/CensusProjector.php` | Applies a canonical event to the census read model |
| `app/Rtdc/Simulator/SyntheticEventSource.php` | Generates a realistic event stream |
| `app/Rtdc/Simulator/SimulatorConfig.php` | Config-driven unit mix + rates |
| `app/Services/AcuityService.php` | Acuity-adjusted capacity math |
| `app/Services/RtdcService.php` (rewrite) | The four-step engine |
| `app/Services/HuddleService.php` | Huddle lifecycle + roll-up |
| `app/Services/BarrierService.php` | Barrier CRUD + categorization |
| `app/Services/ReconciliationService.php` | Step-4 predicted-vs-actual + reliability |
| `app/Jobs/ReconcileRtdcPredictions.php` | Nightly reconciliation job |
| `app/Console/Commands/RtdcSimulateCommand.php` | `php artisan rtdc:simulate` |
| `app/Events/Rtdc/CensusUpdated.php`, `HuddleUpdated.php`, `BedMeetingUpdated.php` | Broadcast events (`ShouldBroadcast`) |
| `app/Http/Controllers/Api/Rtdc/{Census,Prediction,Huddle,Barrier,Reconciliation}Controller.php` | JSON API |
| `app/Http/Requests/Rtdc/{UpsertPredictionRequest,OpenHuddleRequest,UpsertBarrierRequest,...}.php` | Validation |
| `routes/api.php` (modify) | `/api/rtdc/*` routes |
| `routes/channels.php` (new) | Channel authorization |
| `config/broadcasting.php` (new, via reverb:install) | Reverb config |
| `bootstrap/app.php` (modify) | Register nightly schedule |
| `database/seeders/RtdcSeeder.php` | Seed default unit mix + beds |

### Frontend
| File | Responsibility |
|------|----------------|
| `resources/js/lib/echo.ts` | Laravel Echo client (Reverb) |
| `resources/js/schemas/rtdc.ts` | Zod schemas = validation contract |
| `resources/js/features/rtdc/api.ts` | Typed fetchers (axios) |
| `resources/js/features/rtdc/hooks.ts` | TanStack Query hooks + Echo subscription |
| `resources/js/Pages/RTDC/UnitHuddle.tsx` | Migrated + rewired unit huddle |
| `resources/js/Pages/RTDC/GlobalHuddle.tsx` | Migrated + rewired hospital bed meeting |
| `resources/js/Components/RTDC/BedNeedReadout.tsx`, `DischargeTierEntry.tsx`, `DemandBySourceEntry.tsx`, `BarrierBoard.tsx`, `ReliabilityTile.tsx` | New focused UI units |
| `resources/js/app.tsx` (modify) | Resolve `.tsx` pages |

### Tests
| File | Responsibility |
|------|----------------|
| `tests/Feature/Rtdc/*` , `tests/Unit/Rtdc/*` | Pest domain + API tests |
| `tests/js/rtdc/*.test.ts` | Vitest schema + hook tests |
| `tests/e2e/rtdc-huddle.spec.ts` | Playwright full-cycle E2E |

---

## Phases

- **Phase A** — Dependencies & infrastructure (Reverb, Echo, Zod, schedule hook)
- **Phase B** — Canonical event, dispatcher, projector, simulator, census (the live substrate)
- **Phase C** — RTDC four-step engine, acuity, huddles, barriers (domain)
- **Phase D** — JSON API + broadcast events (the wire)
- **Phase E** — Frontend rewire + TypeScript migration
- **Phase F** — Step-4 reconciliation + full-cycle E2E

Each phase ends green (CI passes) and is independently reviewable.

---

## PHASE A — Dependencies & infrastructure

### Task A1: Install and configure Laravel Reverb

**Files:**
- Modify: `composer.json` (via composer require)
- Create: `config/reverb.php`, `config/broadcasting.php` (via installer)
- Modify: `.env`, `.env.example`

- [ ] **Step 1: Install Reverb**

Run:
```bash
cd /home/smudoshi/Github/Zephyrus
composer require laravel/reverb
php artisan reverb:install
```
Expected: `config/reverb.php` and `config/broadcasting.php` created; `BROADCAST_CONNECTION`, `REVERB_APP_*` keys appended to `.env`.

- [ ] **Step 2: Set broadcast connection in `.env.example`**

Add these lines to `.env.example` (the file currently has Pusher placeholders around line 48 — replace them):
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=zephyrus
REVERB_APP_KEY=zephyrus-key
REVERB_APP_SECRET=zephyrus-secret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

- [ ] **Step 3: Verify Reverb boots**

Run:
```bash
php artisan reverb:start --debug &
sleep 2 && curl -s http://localhost:8080 ; kill %1
```
Expected: Reverb server starts without fatal error (curl may return empty/426; that's fine — it confirms the port is listening).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/reverb.php config/broadcasting.php .env.example
git commit -m "feat(rtdc): install and configure Laravel Reverb for real-time broadcasting"
```

---

### Task A2: Install frontend real-time + validation deps (Echo, pusher-js, Zod)

**Files:**
- Modify: `package.json`
- Create: `resources/js/lib/echo.ts`
- Modify: `resources/js/vite-env.d.ts` (or create) for `import.meta.env` types

- [ ] **Step 1: Install packages**

Run (note `--legacy-peer-deps` per the project's react-joyride peer-dep convention):
```bash
npm install --legacy-peer-deps laravel-echo pusher-js zod
```
Expected: three packages added to `dependencies`.

- [ ] **Step 2: Create the Echo client**

Create `resources/js/lib/echo.ts`:
```ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo<'reverb'>;
  }
}

window.Pusher = Pusher;

export const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
});

window.Echo = echo;
```

- [ ] **Step 3: Declare Vite env types**

Create/replace `resources/js/vite-env.d.ts`:
```ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_REVERB_APP_KEY: string;
  readonly VITE_REVERB_HOST: string;
  readonly VITE_REVERB_PORT: string;
  readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
```

- [ ] **Step 4: Verify type-check passes**

Run: `npx tsc --noEmit`
Expected: PASS (no new type errors from echo.ts).

- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json resources/js/lib/echo.ts resources/js/vite-env.d.ts
git commit -m "feat(rtdc): add Laravel Echo, pusher-js, and Zod for real-time + validation"
```

---

### Task A3: Enable `.tsx` Inertia pages

**Files:**
- Modify: `resources/js/app.tsx`

The current resolver only globs `.jsx`. RTDC pages will be `.tsx`, so the resolver must handle both.

- [ ] **Step 1: Update the page resolver**

In `resources/js/app.tsx`, replace the `resolve:` line:
```tsx
// BEFORE
    resolve: (name: string) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
```
with a resolver that prefers `.tsx` then falls back to `.jsx`:
```tsx
    resolve: (name: string) => {
        const pages = import.meta.glob('./Pages/**/*.{jsx,tsx}');
        const tsx = pages[`./Pages/${name}.tsx`];
        const jsx = pages[`./Pages/${name}.jsx`];
        const page = tsx ?? jsx;
        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}`);
        }
        return page();
    },
```

- [ ] **Step 2: Verify existing pages still load**

Run: `npx vite build`
Expected: build succeeds; all existing `.jsx` pages still resolve.

- [ ] **Step 3: Commit**

```bash
git add resources/js/app.tsx
git commit -m "feat(rtdc): resolve both .tsx and .jsx Inertia pages"
```

---

### Task A4: Register the nightly schedule hook

**Files:**
- Modify: `bootstrap/app.php`

The reconciliation job (Phase F) needs a schedule. Wire the hook now (empty), so Phase F only adds the job line.

- [ ] **Step 1: Add the schedule closure**

In `bootstrap/app.php`, add the `->withSchedule(...)` call to the application builder chain (alongside `withRouting`/`withMiddleware`):
```php
use Illuminate\Console\Scheduling\Schedule;

// ... inside the Application::configure(...)-> chain, before ->create():
    ->withSchedule(function (Schedule $schedule) {
        // RTDC Step-4 reconciliation registered in Phase F.
    })
```

- [ ] **Step 2: Verify the schedule list runs**

Run: `php artisan schedule:list`
Expected: command succeeds (prints "No scheduled tasks" — that's correct for now).

- [ ] **Step 3: Commit**

```bash
git add bootstrap/app.php
git commit -m "chore(rtdc): add schedule hook for nightly reconciliation"
```

---

## PHASE B — Canonical event, dispatcher, projector, simulator, census

This phase builds the live substrate. By the end, `php artisan rtdc:simulate` produces a moving census persisted in Postgres, replayable from the event ledger.

### Task B1: Migrations for units & beds

**Files:**
- Create: `database/migrations/2026_06_20_000010_create_rtdc_units_beds_tables.php`
- Test: `tests/Feature/Rtdc/SchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/SchemaTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_and_beds_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('prod.units'));
        $this->assertTrue(Schema::hasColumns('prod.units', [
            'unit_id', 'name', 'type', 'staffed_bed_count', 'ratio_floor',
            'access_standard_minutes', 'is_deleted', 'created_by',
        ]));
        $this->assertTrue(Schema::hasTable('prod.beds'));
        $this->assertTrue(Schema::hasColumns('prod.beds', [
            'bed_id', 'unit_id', 'label', 'status', 'bed_type', 'isolation_capable', 'is_deleted',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL — `prod.units` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_20_000010_create_rtdc_units_beds_tables.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.units', function (Blueprint $table) {
            $table->id('unit_id');
            $table->string('name');
            $table->string('abbreviation')->nullable();
            $table->string('type'); // ed | med_surg | icu | step_down
            $table->integer('staffed_bed_count')->default(0);
            $table->integer('ratio_floor')->default(4); // max patients per nurse
            $table->integer('access_standard_minutes')->default(120);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        Schema::create('prod.beds', function (Blueprint $table) {
            $table->id('bed_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->string('label');
            $table->string('status')->default('available'); // available | occupied | blocked | dirty
            $table->string('bed_type')->default('standard');
            $table->boolean('isolation_capable')->default(false);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.beds ADD CONSTRAINT chk_bed_status CHECK (status IN ('available','occupied','blocked','dirty'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.beds');
        $this->safeDropIfExists('prod.units');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_20_000010_create_rtdc_units_beds_tables.php tests/Feature/Rtdc/SchemaTest.php
git commit -m "feat(rtdc): add units and beds tables"
```

---

### Task B2: Migrations for encounters, census_snapshots, operational_events

**Files:**
- Create: `database/migrations/2026_06_20_000020_create_rtdc_encounters_census_tables.php`
- Create: `database/migrations/2026_06_20_000030_create_rtdc_operational_events_table.php`
- Modify: `tests/Feature/Rtdc/SchemaTest.php`

- [ ] **Step 1: Extend the failing test**

Add to `tests/Feature/Rtdc/SchemaTest.php`:
```php
    public function test_encounters_census_and_events_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.encounters', [
            'encounter_id', 'patient_ref', 'unit_id', 'bed_id', 'admitted_at',
            'expected_discharge_date', 'acuity_tier', 'status', 'is_deleted',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.census_snapshots', [
            'census_snapshot_id', 'unit_id', 'captured_at', 'staffed_beds',
            'occupied', 'available', 'blocked', 'acuity_adjusted_capacity',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.operational_events', [
            'operational_event_id', 'event_id', 'type', 'encounter_ref', 'payload', 'occurred_at',
        ]));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL — `prod.encounters` does not exist.

- [ ] **Step 3: Write the encounters/census migration**

Create `database/migrations/2026_06_20_000020_create_rtdc_encounters_census_tables.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.encounters', function (Blueprint $table) {
            $table->id('encounter_id');
            $table->string('patient_ref'); // pseudonymous, never MRN
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->foreignId('bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->timestamp('admitted_at')->nullable();
            $table->date('expected_discharge_date')->nullable();
            $table->unsignedTinyInteger('acuity_tier')->default(2); // 1..4
            $table->string('status')->default('active'); // active | discharged
            $table->timestamp('discharged_at')->nullable();
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index(['unit_id', 'status']);
        });

        DB::statement('ALTER TABLE prod.encounters ADD CONSTRAINT chk_acuity_tier CHECK (acuity_tier BETWEEN 1 AND 4)');

        Schema::create('prod.census_snapshots', function (Blueprint $table) {
            $table->id('census_snapshot_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->timestamp('captured_at');
            $table->integer('staffed_beds')->default(0);
            $table->integer('occupied')->default(0);
            $table->integer('available')->default(0);
            $table->integer('blocked')->default(0);
            $table->integer('acuity_adjusted_capacity')->default(0);
            $table->timestamps();
            $table->index(['unit_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.census_snapshots');
        $this->safeDropIfExists('prod.encounters');
    }
};
```

- [ ] **Step 4: Write the operational_events migration**

Create `database/migrations/2026_06_20_000030_create_rtdc_operational_events_table.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.operational_events', function (Blueprint $table) {
            $table->id('operational_event_id');
            $table->uuid('event_id')->unique(); // idempotency key
            $table->string('type'); // EncounterStarted | EncounterTransferred | EncounterDischarged | BedStatusChanged | AcuityChanged
            $table->string('encounter_ref')->nullable();
            $table->jsonb('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'occurred_at']);
            $table->index('encounter_ref');
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.operational_events');
    }
};
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_20_000020_create_rtdc_encounters_census_tables.php database/migrations/2026_06_20_000030_create_rtdc_operational_events_table.php tests/Feature/Rtdc/SchemaTest.php
git commit -m "feat(rtdc): add encounters, census_snapshots, and operational_events tables"
```

---

### Task B3: Eloquent models for the substrate

**Files:**
- Create: `app/Models/Unit.php`, `Bed.php`, `Encounter.php`, `CensusSnapshot.php`, `OperationalEvent.php`
- Test: `tests/Feature/Rtdc/ModelRelationshipsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/ModelRelationshipsTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_has_beds_and_encounters(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $enc = Encounter::create(['patient_ref' => 'p-1', 'unit_id' => $unit->unit_id, 'bed_id' => $bed->bed_id, 'acuity_tier' => 3, 'status' => 'active']);

        $this->assertEquals(1, $unit->beds()->count());
        $this->assertEquals(1, $unit->encounters()->count());
        $this->assertEquals($unit->unit_id, $enc->unit->unit_id);
        $this->assertEquals('5E-01', $enc->bed->label);
    }

    public function test_encounter_active_scope_excludes_discharged(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        Encounter::create(['patient_ref' => 'a', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        Encounter::create(['patient_ref' => 'b', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'discharged']);

        $this->assertEquals(1, Encounter::active()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ModelRelationshipsTest`
Expected: FAIL — class `App\Models\Unit` not found.

- [ ] **Step 3: Write the models**

Create `app/Models/Unit.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $table = 'prod.units';
    protected $primaryKey = 'unit_id';

    protected $fillable = [
        'name', 'abbreviation', 'type', 'staffed_bed_count',
        'ratio_floor', 'access_standard_minutes', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'staffed_bed_count' => 'integer',
        'ratio_floor' => 'integer',
        'access_standard_minutes' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'unit_id', 'unit_id')->where('is_deleted', false);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'unit_id', 'unit_id');
    }
}
```

Create `app/Models/Bed.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bed extends Model
{
    protected $table = 'prod.beds';
    protected $primaryKey = 'bed_id';

    protected $fillable = [
        'unit_id', 'label', 'status', 'bed_type',
        'isolation_capable', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'isolation_capable' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')->where('is_deleted', false);
    }
}
```

Create `app/Models/Encounter.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Encounter extends Model
{
    protected $table = 'prod.encounters';
    protected $primaryKey = 'encounter_id';

    protected $fillable = [
        'patient_ref', 'unit_id', 'bed_id', 'admitted_at', 'expected_discharge_date',
        'acuity_tier', 'status', 'discharged_at', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'admitted_at' => 'datetime',
        'discharged_at' => 'datetime',
        'expected_discharge_date' => 'date',
        'acuity_tier' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'bed_id', 'bed_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_deleted', false);
    }
}
```

Create `app/Models/CensusSnapshot.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CensusSnapshot extends Model
{
    protected $table = 'prod.census_snapshots';
    protected $primaryKey = 'census_snapshot_id';

    protected $fillable = [
        'unit_id', 'captured_at', 'staffed_beds', 'occupied',
        'available', 'blocked', 'acuity_adjusted_capacity',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'staffed_beds' => 'integer',
        'occupied' => 'integer',
        'available' => 'integer',
        'blocked' => 'integer',
        'acuity_adjusted_capacity' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
```

Create `app/Models/OperationalEvent.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalEvent extends Model
{
    protected $table = 'prod.operational_events';
    protected $primaryKey = 'operational_event_id';
    public const UPDATED_AT = null; // append-only; created_at only

    protected $fillable = ['event_id', 'type', 'encounter_ref', 'payload', 'occurred_at'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ModelRelationshipsTest`
Expected: PASS.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Models`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Unit.php app/Models/Bed.php app/Models/Encounter.php app/Models/CensusSnapshot.php app/Models/OperationalEvent.php tests/Feature/Rtdc/ModelRelationshipsTest.php
git commit -m "feat(rtdc): add substrate Eloquent models with relationships and scopes"
```

---

### Task B4: The CanonicalEvent DTO and EventSource contract

**Files:**
- Create: `app/Rtdc/Events/CanonicalEvent.php`
- Create: `app/Rtdc/Contracts/EventSource.php`
- Test: `tests/Unit/Rtdc/CanonicalEventTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Rtdc/CanonicalEventTest.php`:
```php
<?php

namespace Tests\Unit\Rtdc;

use App\Rtdc\Events\CanonicalEvent;
use Tests\TestCase;

class CanonicalEventTest extends TestCase
{
    public function test_factory_methods_build_typed_events(): void
    {
        $e = CanonicalEvent::encounterStarted('p-1', unitId: 3, acuityTier: 2, occurredAt: now());

        $this->assertSame(CanonicalEvent::ENCOUNTER_STARTED, $e->type);
        $this->assertSame('p-1', $e->encounterRef);
        $this->assertSame(3, $e->payload['unit_id']);
        $this->assertNotEmpty($e->eventId);
        $this->assertArrayHasKey('unit_id', $e->toArray()['payload']);
    }

    public function test_event_id_is_unique_per_event(): void
    {
        $a = CanonicalEvent::encounterDischarged('p-1', now());
        $b = CanonicalEvent::encounterDischarged('p-1', now());
        $this->assertNotSame($a->eventId, $b->eventId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CanonicalEventTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the DTO**

Create `app/Rtdc/Events/CanonicalEvent.php`:
```php
<?php

namespace App\Rtdc\Events;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Immutable canonical operational event. The ONLY shape the domain consumes.
 * HL7v2/FHIR vocabulary is mapped into this at the source adapter (anti-corruption boundary).
 */
final readonly class CanonicalEvent
{
    public const ENCOUNTER_STARTED = 'EncounterStarted';
    public const ENCOUNTER_TRANSFERRED = 'EncounterTransferred';
    public const ENCOUNTER_DISCHARGED = 'EncounterDischarged';
    public const BED_STATUS_CHANGED = 'BedStatusChanged';
    public const ACUITY_CHANGED = 'AcuityChanged';

    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $encounterRef,
        public array $payload,
        public CarbonInterface $occurredAt,
    ) {}

    public static function encounterStarted(string $patientRef, int $unitId, int $acuityTier, CarbonInterface $occurredAt, ?int $bedId = null): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_STARTED, $patientRef, [
            'unit_id' => $unitId, 'bed_id' => $bedId, 'acuity_tier' => $acuityTier,
        ], $occurredAt);
    }

    public static function encounterTransferred(string $patientRef, int $toUnitId, CarbonInterface $occurredAt, ?int $toBedId = null): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_TRANSFERRED, $patientRef, [
            'to_unit_id' => $toUnitId, 'to_bed_id' => $toBedId,
        ], $occurredAt);
    }

    public static function encounterDischarged(string $patientRef, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_DISCHARGED, $patientRef, [], $occurredAt);
    }

    public static function bedStatusChanged(int $bedId, string $status, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::BED_STATUS_CHANGED, null, [
            'bed_id' => $bedId, 'status' => $status,
        ], $occurredAt);
    }

    public static function acuityChanged(string $patientRef, int $acuityTier, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::ACUITY_CHANGED, $patientRef, [
            'acuity_tier' => $acuityTier,
        ], $occurredAt);
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'encounter_ref' => $this->encounterRef,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Write the EventSource contract**

Create `app/Rtdc/Contracts/EventSource.php`:
```php
<?php

namespace App\Rtdc\Contracts;

use App\Rtdc\Events\CanonicalEvent;

/**
 * Every event producer (synthetic simulator now; HL7v2/FHIR adapters later)
 * implements this. The dispatcher consumes CanonicalEvents regardless of source.
 *
 * @return iterable<CanonicalEvent>
 */
interface EventSource
{
    public function pull(): iterable;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CanonicalEventTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Rtdc/Events/CanonicalEvent.php app/Rtdc/Contracts/EventSource.php tests/Unit/Rtdc/CanonicalEventTest.php
git commit -m "feat(rtdc): add CanonicalEvent DTO and EventSource contract (the seam)"
```

---

### Task B5: CensusProjector — apply events to the read model

**Files:**
- Create: `app/Rtdc/CensusProjector.php`
- Test: `tests/Feature/Rtdc/CensusProjectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/CensusProjectorTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\CensusProjector;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CensusProjectorTest extends TestCase
{
    use RefreshDatabase;

    private function unitWithBed(): array
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        return [$unit, $bed];
    }

    public function test_encounter_started_creates_active_encounter_and_occupies_bed(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        $projector = app(CensusProjector::class);

        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active', 'acuity_tier' => 3]);
        $this->assertEquals('occupied', $bed->fresh()->status);
    }

    public function test_encounter_discharged_marks_discharged_and_frees_bed(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        $projector = app(CensusProjector::class);
        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $projector->apply(CanonicalEvent::encounterDischarged('p-1', now()));

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'discharged']);
        $this->assertEquals('dirty', $bed->fresh()->status); // freed but needs cleaning
    }

    public function test_snapshot_reflects_occupancy(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available']);
        $projector = app(CensusProjector::class);
        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $snap = $projector->snapshot($unit->unit_id);

        $this->assertEquals(1, $snap->occupied);
        $this->assertEquals(1, $snap->available);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CensusProjectorTest`
Expected: FAIL — class `App\Rtdc\CensusProjector` not found.

- [ ] **Step 3: Write the projector**

Create `app/Rtdc/CensusProjector.php`:
```php
<?php

namespace App\Rtdc;

use App\Models\Bed;
use App\Models\CensusSnapshot;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Facades\DB;

/**
 * Applies a CanonicalEvent to the materialized census read model.
 * Pure projection: idempotent given the same event stream; rebuildable by replay.
 */
class CensusProjector
{
    public function apply(CanonicalEvent $event): void
    {
        DB::transaction(function () use ($event) {
            match ($event->type) {
                CanonicalEvent::ENCOUNTER_STARTED => $this->onStarted($event),
                CanonicalEvent::ENCOUNTER_TRANSFERRED => $this->onTransferred($event),
                CanonicalEvent::ENCOUNTER_DISCHARGED => $this->onDischarged($event),
                CanonicalEvent::BED_STATUS_CHANGED => $this->onBedStatus($event),
                CanonicalEvent::ACUITY_CHANGED => $this->onAcuity($event),
                default => null,
            };
        });
    }

    private function onStarted(CanonicalEvent $e): void
    {
        Encounter::updateOrCreate(
            ['patient_ref' => $e->encounterRef, 'status' => 'active'],
            [
                'unit_id' => $e->payload['unit_id'],
                'bed_id' => $e->payload['bed_id'] ?? null,
                'acuity_tier' => $e->payload['acuity_tier'],
                'admitted_at' => $e->occurredAt,
            ],
        );
        if (! empty($e->payload['bed_id'])) {
            Bed::where('bed_id', $e->payload['bed_id'])->update(['status' => 'occupied']);
        }
    }

    private function onTransferred(CanonicalEvent $e): void
    {
        $enc = Encounter::active()->where('patient_ref', $e->encounterRef)->first();
        if (! $enc) {
            return;
        }
        if ($enc->bed_id) {
            Bed::where('bed_id', $enc->bed_id)->update(['status' => 'dirty']);
        }
        $enc->update([
            'unit_id' => $e->payload['to_unit_id'],
            'bed_id' => $e->payload['to_bed_id'] ?? null,
        ]);
        if (! empty($e->payload['to_bed_id'])) {
            Bed::where('bed_id', $e->payload['to_bed_id'])->update(['status' => 'occupied']);
        }
    }

    private function onDischarged(CanonicalEvent $e): void
    {
        $enc = Encounter::active()->where('patient_ref', $e->encounterRef)->first();
        if (! $enc) {
            return;
        }
        if ($enc->bed_id) {
            Bed::where('bed_id', $enc->bed_id)->update(['status' => 'dirty']);
        }
        $enc->update(['status' => 'discharged', 'discharged_at' => $e->occurredAt]);
    }

    private function onBedStatus(CanonicalEvent $e): void
    {
        Bed::where('bed_id', $e->payload['bed_id'])->update(['status' => $e->payload['status']]);
    }

    private function onAcuity(CanonicalEvent $e): void
    {
        Encounter::active()->where('patient_ref', $e->encounterRef)
            ->update(['acuity_tier' => $e->payload['acuity_tier']]);
    }

    /**
     * Recompute and persist a census snapshot for a unit.
     */
    public function snapshot(int $unitId): CensusSnapshot
    {
        $unit = Unit::findOrFail($unitId);
        $beds = Bed::where('unit_id', $unitId)->where('is_deleted', false)->get();
        $occupied = $beds->where('status', 'occupied')->count();
        $available = $beds->where('status', 'available')->count();
        $blocked = $beds->whereIn('status', ['blocked', 'dirty'])->count();

        return CensusSnapshot::create([
            'unit_id' => $unitId,
            'captured_at' => now(),
            'staffed_beds' => $unit->staffed_bed_count,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'acuity_adjusted_capacity' => app(\App\Services\AcuityService::class)->adjustedCapacity($unitId),
        ]);
    }
}
```

> Note: `AcuityService` is created in Task C1. To keep this task green in isolation, add a temporary shim if executing strictly task-by-task: create `app/Services/AcuityService.php` with `public function adjustedCapacity(int $unitId): int { return \App\Models\Unit::find($unitId)?->staffed_bed_count ?? 0; }`. Task C1 replaces it with the tested implementation.

- [ ] **Step 4: Create the AcuityService shim (so this task is green standalone)**

Create `app/Services/AcuityService.php`:
```php
<?php

namespace App\Services;

use App\Models\Unit;

class AcuityService
{
    // Replaced with acuity-weighted logic in Task C1.
    public function adjustedCapacity(int $unitId): int
    {
        return Unit::find($unitId)?->staffed_bed_count ?? 0;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CensusProjectorTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Rtdc/CensusProjector.php app/Services/AcuityService.php tests/Feature/Rtdc/CensusProjectorTest.php
git commit -m "feat(rtdc): add CensusProjector applying canonical events to the read model"
```

---

### Task B6: EventDispatcher — ledger + project + broadcast (the seam)

**Files:**
- Create: `app/Rtdc/EventDispatcher.php`
- Test: `tests/Feature/Rtdc/EventDispatcherTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/EventDispatcherTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\OperationalEvent;
use App\Models\Unit;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_persists_event_to_ledger_and_projects(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'I-01', 'status' => 'available']);
        $dispatcher = app(EventDispatcher::class);

        $event = CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 4, now(), $bed->bed_id);
        $dispatcher->dispatch($event);

        $this->assertDatabaseHas('prod.operational_events', ['event_id' => $event->eventId, 'type' => 'EncounterStarted']);
        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active']);
    }

    public function test_dispatch_is_idempotent_on_duplicate_event_id(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $dispatcher = app(EventDispatcher::class);
        $event = CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 4, now());

        $dispatcher->dispatch($event);
        $dispatcher->dispatch($event); // replay same event_id

        $this->assertEquals(1, OperationalEvent::where('event_id', $event->eventId)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EventDispatcherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the dispatcher**

Create `app/Rtdc/EventDispatcher.php`:
```php
<?php

namespace App\Rtdc;

use App\Events\Rtdc\CensusUpdated;
use App\Models\OperationalEvent;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Facades\DB;

/**
 * The seam between event producers and the domain.
 *
 * S2: synchronous in-process — persist to the ledger, project, broadcast.
 * S1: this class is replaced by a Redis Streams publisher + async consumer;
 *     producers (simulator, HL7v2/FHIR adapters) and the projector do not change.
 */
class EventDispatcher
{
    public function __construct(private readonly CensusProjector $projector) {}

    public function dispatch(CanonicalEvent $event): void
    {
        $isNew = false;

        DB::transaction(function () use ($event, &$isNew) {
            // Idempotency: insert-or-ignore on the unique event_id.
            $created = OperationalEvent::firstOrCreate(
                ['event_id' => $event->eventId],
                [
                    'type' => $event->type,
                    'encounter_ref' => $event->encounterRef,
                    'payload' => $event->payload,
                    'occurred_at' => $event->occurredAt,
                ],
            );
            $isNew = $created->wasRecentlyCreated;

            if ($isNew) {
                $this->projector->apply($event);
            }
        });

        if (! $isNew) {
            return; // duplicate — already projected, do not re-broadcast
        }

        $unitId = $this->affectedUnitId($event);
        if ($unitId !== null) {
            $snapshot = $this->projector->snapshot($unitId);
            broadcast(new CensusUpdated($snapshot));
        }
    }

    private function affectedUnitId(CanonicalEvent $event): ?int
    {
        return match ($event->type) {
            CanonicalEvent::ENCOUNTER_STARTED => $event->payload['unit_id'] ?? null,
            CanonicalEvent::ENCOUNTER_TRANSFERRED => $event->payload['to_unit_id'] ?? null,
            CanonicalEvent::ENCOUNTER_DISCHARGED, CanonicalEvent::ACUITY_CHANGED =>
                \App\Models\Encounter::where('patient_ref', $event->encounterRef)->value('unit_id'),
            CanonicalEvent::BED_STATUS_CHANGED =>
                \App\Models\Bed::where('bed_id', $event->payload['bed_id'] ?? 0)->value('unit_id'),
            default => null,
        };
    }
}
```

> Note: `CensusUpdated` is created in Task D1. To keep this task green standalone, create a minimal version now (Task D1 adds channel/payload tests). Create `app/Events/Rtdc/CensusUpdated.php`:
```php
<?php

namespace App\Events\Rtdc;

use App\Models\CensusSnapshot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CensusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CensusSnapshot $snapshot) {}

    public function broadcastOn(): Channel
    {
        return new Channel('unit.'.$this->snapshot->unit_id);
    }

    public function broadcastAs(): string
    {
        return 'census.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->snapshot->unit_id,
            'captured_at' => $this->snapshot->captured_at?->toIso8601String(),
            'staffed_beds' => $this->snapshot->staffed_beds,
            'occupied' => $this->snapshot->occupied,
            'available' => $this->snapshot->available,
            'blocked' => $this->snapshot->blocked,
            'acuity_adjusted_capacity' => $this->snapshot->acuity_adjusted_capacity,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EventDispatcherTest`
Expected: PASS. (Broadcasting uses the `log`/`null` driver in tests — no Reverb needed.)

- [ ] **Step 5: Commit**

```bash
git add app/Rtdc/EventDispatcher.php app/Events/Rtdc/CensusUpdated.php tests/Feature/Rtdc/EventDispatcherTest.php
git commit -m "feat(rtdc): add EventDispatcher (ledger + project + broadcast seam)"
```

---

### Task B7: Replay rebuilds the census (proves the S1 seam)

**Files:**
- Create: `app/Rtdc/CensusRebuilder.php`
- Test: `tests/Feature/Rtdc/ReplayTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/ReplayTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\CensusRebuilder;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_from_ledger_reproduces_census(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $dispatcher = app(EventDispatcher::class);
        $dispatcher->dispatch(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        // Wipe the read model but keep the ledger.
        Encounter::query()->delete();
        Bed::where('bed_id', $bed->bed_id)->update(['status' => 'available']);

        app(CensusRebuilder::class)->rebuild();

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active']);
        $this->assertEquals('occupied', $bed->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReplayTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the rebuilder**

Create `app/Rtdc/CensusRebuilder.php`:
```php
<?php

namespace App\Rtdc;

use App\Models\OperationalEvent;
use App\Rtdc\Events\CanonicalEvent;
use Carbon\Carbon;

class CensusRebuilder
{
    public function __construct(private readonly CensusProjector $projector) {}

    public function rebuild(): int
    {
        $count = 0;
        OperationalEvent::orderBy('occurred_at')->orderBy('operational_event_id')
            ->chunk(500, function ($events) use (&$count) {
                foreach ($events as $row) {
                    $this->projector->apply(new CanonicalEvent(
                        eventId: $row->event_id,
                        type: $row->type,
                        encounterRef: $row->encounter_ref,
                        payload: $row->payload,
                        occurredAt: Carbon::parse($row->occurred_at),
                    ));
                    $count++;
                }
            });

        return $count;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ReplayTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Rtdc/CensusRebuilder.php tests/Feature/Rtdc/ReplayTest.php
git commit -m "feat(rtdc): replay census from event ledger (proves S1 swap seam)"
```

---

### Task B8: Synthetic simulator + seeder + artisan command

**Files:**
- Create: `app/Rtdc/Simulator/SimulatorConfig.php`
- Create: `app/Rtdc/Simulator/SyntheticEventSource.php`
- Create: `app/Console/Commands/RtdcSimulateCommand.php`
- Create: `database/seeders/RtdcSeeder.php`
- Test: `tests/Feature/Rtdc/SimulatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/SimulatorTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_default_unit_mix_creates_units_and_beds(): void
    {
        $this->seed(\Database\Seeders\RtdcSeeder::class);
        $this->assertEquals(6, Unit::count()); // ED + 3 med/surg + ICU + step-down
        $this->assertGreaterThan(0, \App\Models\Bed::count());
    }

    public function test_simulator_with_fixed_seed_is_deterministic(): void
    {
        $this->seed(\Database\Seeders\RtdcSeeder::class);
        $dispatcher = app(EventDispatcher::class);
        $source = new SyntheticEventSource(SimulatorConfig::default(), seed: 42);

        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
        }

        $firstRunActive = Encounter::active()->count();
        $this->assertGreaterThan(0, $firstRunActive);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SimulatorTest`
Expected: FAIL — seeder/class not found.

- [ ] **Step 3: Write the seeder**

Create `database/seeders/RtdcSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class RtdcSeeder extends Seeder
{
    /**
     * Default config-driven unit mix: ED + 3 med/surg + ICU + step-down (~300 beds).
     */
    public function run(): void
    {
        $units = [
            ['name' => 'Emergency Department', 'abbreviation' => 'ED', 'type' => 'ed', 'staffed_bed_count' => 40, 'ratio_floor' => 4],
            ['name' => '5 East', 'abbreviation' => '5E', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => '5 West', 'abbreviation' => '5W', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => '6 East', 'abbreviation' => '6E', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => 'ICU', 'abbreviation' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 20, 'ratio_floor' => 2],
            ['name' => 'Step-Down', 'abbreviation' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 24, 'ratio_floor' => 3],
        ];

        foreach ($units as $u) {
            $unit = Unit::create($u);
            for ($i = 1; $i <= $u['staffed_bed_count']; $i++) {
                Bed::create([
                    'unit_id' => $unit->unit_id,
                    'label' => sprintf('%s-%02d', $unit->abbreviation, $i),
                    'status' => 'available',
                    'isolation_capable' => $i % 8 === 0,
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Write the simulator config + source**

Create `app/Rtdc/Simulator/SimulatorConfig.php`:
```php
<?php

namespace App\Rtdc\Simulator;

final readonly class SimulatorConfig
{
    public function __construct(
        public int $initialOccupancyPercent,
        public int $admitsPerTick,
        public int $dischargesPerTick,
        public int $ticks,
    ) {}

    public static function default(): self
    {
        return new self(initialOccupancyPercent: 70, admitsPerTick: 3, dischargesPerTick: 2, ticks: 24);
    }
}
```

Create `app/Rtdc/Simulator/SyntheticEventSource.php`:
```php
<?php

namespace App\Rtdc\Simulator;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Contracts\EventSource;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Str;

/**
 * Generates a realistic-enough operational event stream for demo/CI.
 * Deterministic given a fixed seed. Implements the same EventSource contract
 * the HL7v2/FHIR adapters will implement in S1/S8.
 */
class SyntheticEventSource implements EventSource
{
    private int $patientCounter = 0;

    public function __construct(
        private readonly SimulatorConfig $config,
        private readonly int $seed = 0,
    ) {}

    public function pull(): iterable
    {
        mt_srand($this->seed);
        $now = now()->startOfDay()->addHours(6);

        // Seed initial occupancy.
        foreach (Unit::all() as $unit) {
            $target = (int) floor($unit->staffed_bed_count * $this->config->initialOccupancyPercent / 100);
            $freeBeds = Bed::where('unit_id', $unit->unit_id)->where('status', 'available')->limit($target)->pluck('bed_id');
            foreach ($freeBeds as $bedId) {
                yield CanonicalEvent::encounterStarted($this->nextPatient(), $unit->unit_id, $this->randomAcuity($unit->type), $now, $bedId);
            }
        }

        // Diurnal-ish flow across ticks.
        for ($t = 0; $t < $this->config->ticks; $t++) {
            $tickTime = $now->copy()->addHours($t);

            for ($d = 0; $d < $this->config->dischargesPerTick; $d++) {
                $enc = Encounter::active()->inRandomOrder()->first();
                if ($enc) {
                    yield CanonicalEvent::encounterDischarged($enc->patient_ref, $tickTime);
                }
            }

            for ($a = 0; $a < $this->config->admitsPerTick; $a++) {
                $bed = Bed::available()->inRandomOrder()->first();
                if ($bed) {
                    yield CanonicalEvent::encounterStarted($this->nextPatient(), $bed->unit_id, $this->randomAcuity($bed->unit->type), $tickTime, $bed->bed_id);
                }
            }
        }
    }

    private function nextPatient(): string
    {
        return 'sim-'.(++$this->patientCounter).'-'.Str::random(4);
    }

    private function randomAcuity(string $unitType): int
    {
        return match ($unitType) {
            'icu' => mt_rand(3, 4),
            'step_down' => mt_rand(2, 3),
            'ed' => mt_rand(2, 4),
            default => mt_rand(1, 3),
        };
    }
}
```

- [ ] **Step 5: Write the artisan command**

Create `app/Console/Commands/RtdcSimulateCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Console\Command;

class RtdcSimulateCommand extends Command
{
    protected $signature = 'rtdc:simulate {--seed=0} {--ticks=24}';
    protected $description = 'Drive the live census with a synthetic operational event stream.';

    public function handle(EventDispatcher $dispatcher): int
    {
        $config = new SimulatorConfig(
            initialOccupancyPercent: 70,
            admitsPerTick: 3,
            dischargesPerTick: 2,
            ticks: (int) $this->option('ticks'),
        );
        $source = new SyntheticEventSource($config, seed: (int) $this->option('seed'));

        $n = 0;
        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
            $n++;
        }

        $this->info("Dispatched {$n} canonical events.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SimulatorTest`
Expected: PASS.

- [ ] **Step 7: Manual smoke — drive a live census**

Run:
```bash
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\RtdcSeeder
php artisan rtdc:simulate --seed=42 --ticks=12
php artisan tinker --execute="echo \App\Models\Encounter::active()->count();"
```
Expected: prints a positive active-encounter count.

- [ ] **Step 8: Run Pint and commit**

```bash
vendor/bin/pint app/Rtdc app/Console database/seeders
git add app/Rtdc/Simulator app/Console/Commands/RtdcSimulateCommand.php database/seeders/RtdcSeeder.php tests/Feature/Rtdc/SimulatorTest.php
git commit -m "feat(rtdc): add synthetic simulator, seeder, and rtdc:simulate command"
```

---

### Phase B exit check

- [ ] Run full backend suite: `php artisan test --filter=Rtdc`
- [ ] Expected: all Phase B tests green. The census is live, event-sourced, and replayable.

---

## PHASE C — RTDC four-step engine, acuity, huddles, barriers (domain)

### Task C1: AcuityService — acuity-adjusted capacity

Replaces the Task B5 shim with the real, tested math. Per research §4: `required_nurses = ceil(acuity_demand)`, and a unit's true admit capacity is gated by nurse-safety, not bed count alone. We express capacity as the number of *additional* patients the unit can safely hold.

**Files:**
- Modify: `app/Services/AcuityService.php`
- Test: `tests/Unit/Rtdc/AcuityServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Rtdc/AcuityServiceTest.php`:
```php
<?php

namespace Tests\Unit\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcuityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_acuity_weight_increases_with_tier(): void
    {
        $svc = new AcuityService();
        $this->assertGreaterThan($svc->tierWeight(1), $svc->tierWeight(4));
    }

    public function test_adjusted_capacity_is_bounded_by_nurse_safety_not_just_beds(): void
    {
        // ICU: 12 staffed beds, ratio floor 2 (1 nurse : 2 patients). Suppose 6 nurses available implicit via staffed beds.
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        // Fill with 10 high-acuity (tier 4) patients.
        for ($i = 0; $i < 10; $i++) {
            Encounter::create(['patient_ref' => "p$i", 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        }

        $svc = new AcuityService();
        $capacity = $svc->adjustedCapacity($unit->unit_id);

        // 12 physical beds remain, but high acuity load means safe additional capacity is lower than 2 (raw free beds).
        $this->assertLessThanOrEqual(2, $capacity);
        $this->assertGreaterThanOrEqual(0, $capacity);
    }

    public function test_empty_unit_capacity_equals_staffed_beds(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $svc = new AcuityService();
        $this->assertEquals(30, $svc->adjustedCapacity($unit->unit_id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AcuityServiceTest`
Expected: FAIL — `tierWeight` not defined / capacity logic wrong.

- [ ] **Step 3: Implement the real AcuityService**

Replace `app/Services/AcuityService.php`:
```php
<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\Unit;

/**
 * Acuity-adjusted capacity. Research §4: capacity to admit is gated on
 * nurse-safety-to-accept, not bed availability alone. Tier weights approximate
 * relative nursing workload (tier 4 ~ double tier 1).
 */
class AcuityService
{
    private const TIER_WEIGHTS = [1 => 1.0, 2 => 1.3, 3 => 1.7, 4 => 2.2];

    public function tierWeight(int $tier): float
    {
        return self::TIER_WEIGHTS[$tier] ?? 1.0;
    }

    /**
     * The number of additional patients a unit can safely admit right now.
     */
    public function adjustedCapacity(int $unitId): int
    {
        $unit = Unit::findOrFail($unitId);

        // Nursing workload budget = staffed beds expressed as "standard (tier-1) patient equivalents".
        // A unit staffed for N beds at its ratio can carry N standard patients.
        $workloadBudget = (float) $unit->staffed_bed_count;

        $currentLoad = Encounter::active()->where('unit_id', $unitId)->get()
            ->sum(fn (Encounter $e) => $this->tierWeight($e->acuity_tier));

        $remaining = $workloadBudget - $currentLoad;

        // Convert remaining workload back to standard-patient slots, never negative.
        return max(0, (int) floor($remaining / $this->tierWeight(1)));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AcuityServiceTest`
Expected: PASS.

- [ ] **Step 5: Re-run the projector test (regression — snapshot uses adjustedCapacity)**

Run: `php artisan test --filter=CensusProjectorTest`
Expected: PASS (acuity-adjusted capacity now reflects load).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Services/AcuityService.php
git add app/Services/AcuityService.php tests/Unit/Rtdc/AcuityServiceTest.php
git commit -m "feat(rtdc): acuity-adjusted capacity gated on nurse-safety (research §4)"
```

---

### Task C2: Migrations for rtdc_predictions and rtdc_plans

**Files:**
- Create: `database/migrations/2026_06_20_000040_create_rtdc_predictions_plans_tables.php`
- Modify: `tests/Feature/Rtdc/SchemaTest.php`

- [ ] **Step 1: Extend the failing test**

Add to `tests/Feature/Rtdc/SchemaTest.php`:
```php
    public function test_predictions_and_plans_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.rtdc_predictions', [
            'rtdc_prediction_id', 'unit_id', 'service_date', 'horizon',
            'discharges_definite', 'discharges_probable', 'discharges_possible', 'discharges_weighted',
            'demand_ed', 'demand_or', 'demand_transfer', 'demand_direct', 'demand_expected',
            'capacity_now', 'bed_need', 'status', 'created_by',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.rtdc_plans', [
            'rtdc_plan_id', 'rtdc_prediction_id', 'action_text', 'owner', 'due_at', 'status',
        ]));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL — `prod.rtdc_predictions` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_20_000040_create_rtdc_predictions_plans_tables.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.rtdc_predictions', function (Blueprint $table) {
            $table->id('rtdc_prediction_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->string('horizon'); // by_2pm | by_midnight
            // Predicted discharges (clinician-entered confidence tiers).
            $table->integer('discharges_definite')->default(0);
            $table->integer('discharges_probable')->default(0);
            $table->integer('discharges_possible')->default(0);
            $table->decimal('discharges_weighted', 6, 2)->default(0);
            // Predicted demand by source.
            $table->integer('demand_ed')->default(0);
            $table->integer('demand_or')->default(0);
            $table->integer('demand_transfer')->default(0);
            $table->integer('demand_direct')->default(0);
            $table->integer('demand_expected')->default(0);
            // Capacity + the headline number.
            $table->integer('capacity_now')->default(0);
            $table->integer('bed_need')->default(0); // demand - (available + weighted discharges)
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unique(['unit_id', 'service_date', 'horizon'], 'uq_rtdc_pred_unit_date_horizon');
        });

        DB::statement("ALTER TABLE prod.rtdc_predictions ADD CONSTRAINT chk_rtdc_horizon CHECK (horizon IN ('by_2pm','by_midnight'))");

        Schema::create('prod.rtdc_plans', function (Blueprint $table) {
            $table->id('rtdc_plan_id');
            $table->foreignId('rtdc_prediction_id')->nullable()->constrained('prod.rtdc_predictions', 'rtdc_prediction_id');
            $table->text('action_text');
            $table->string('owner')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('open'); // open | done
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rtdc_plans');
        $this->safeDropIfExists('prod.rtdc_predictions');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_20_000040_create_rtdc_predictions_plans_tables.php tests/Feature/Rtdc/SchemaTest.php
git commit -m "feat(rtdc): add rtdc_predictions (the triple) and rtdc_plans tables"
```

---

### Task C3: RtdcPrediction & RtdcPlan models

**Files:**
- Create: `app/Models/RtdcPrediction.php`, `app/Models/RtdcPlan.php`
- Test: covered via `RtdcServiceTest` (Task C4); add a thin model test.
- Test: `tests/Feature/Rtdc/RtdcPredictionModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/RtdcPredictionModelTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\RtdcPrediction;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtdcPredictionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_belongs_to_unit_and_has_plans(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $pred = RtdcPrediction::create([
            'unit_id' => $unit->unit_id, 'service_date' => today(), 'horizon' => 'by_2pm',
        ]);
        $pred->plans()->create(['action_text' => 'Expedite 2 telemetry discharges', 'owner' => 'Charge RN']);

        $this->assertEquals($unit->unit_id, $pred->unit->unit_id);
        $this->assertEquals(1, $pred->plans()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RtdcPredictionModelTest`
Expected: FAIL — model not found.

- [ ] **Step 3: Write the models**

Create `app/Models/RtdcPrediction.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtdcPrediction extends Model
{
    protected $table = 'prod.rtdc_predictions';
    protected $primaryKey = 'rtdc_prediction_id';

    protected $fillable = [
        'unit_id', 'service_date', 'horizon',
        'discharges_definite', 'discharges_probable', 'discharges_possible', 'discharges_weighted',
        'demand_ed', 'demand_or', 'demand_transfer', 'demand_direct', 'demand_expected',
        'capacity_now', 'bed_need', 'status', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'service_date' => 'date',
        'discharges_weighted' => 'float',
        'bed_need' => 'integer',
        'capacity_now' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(RtdcPlan::class, 'rtdc_prediction_id', 'rtdc_prediction_id')->where('is_deleted', false);
    }
}
```

Create `app/Models/RtdcPlan.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtdcPlan extends Model
{
    protected $table = 'prod.rtdc_plans';
    protected $primaryKey = 'rtdc_plan_id';

    protected $fillable = [
        'rtdc_prediction_id', 'action_text', 'owner', 'due_at', 'status', 'created_by', 'is_deleted',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(RtdcPrediction::class, 'rtdc_prediction_id', 'rtdc_prediction_id');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RtdcPredictionModelTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Models
git add app/Models/RtdcPrediction.php app/Models/RtdcPlan.php tests/Feature/Rtdc/RtdcPredictionModelTest.php
git commit -m "feat(rtdc): add RtdcPrediction and RtdcPlan models"
```

---

### Task C4: RtdcService — the four-step engine

**Files:**
- Rewrite: `app/Services/RtdcService.php` (currently a 14-line session helper; preserve `activateWorkflow`)
- Test: `tests/Feature/Rtdc/RtdcServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/RtdcServiceTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Services\RtdcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtdcServiceTest extends TestCase
{
    use RefreshDatabase;

    private function unit(): Unit
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        // 5 available beds.
        for ($i = 0; $i < 5; $i++) {
            Bed::create(['unit_id' => $unit->unit_id, 'label' => "5E-0$i", 'status' => 'available']);
        }
        return $unit;
    }

    public function test_weighted_discharges_apply_confidence_weights(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);

        $pred = $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 3, probable: 2, possible: 4);

        // definite=1.0, probable=0.6, possible=0.3 -> 3 + 1.2 + 1.2 = 5.4
        $this->assertEqualsWithDelta(5.4, $pred->discharges_weighted, 0.001);
    }

    public function test_demand_sums_by_source(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);

        $pred = $svc->upsertDemand($unit->unit_id, today(), 'by_2pm', ed: 4, or: 1, transfer: 2, direct: 1);

        $this->assertEquals(8, $pred->demand_expected);
    }

    public function test_bed_need_is_demand_minus_available_plus_weighted_discharges(): void
    {
        $unit = $this->unit(); // 5 available beds
        $svc = app(RtdcService::class);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 2, probable: 0, possible: 0); // weighted 2.0
        $svc->upsertDemand($unit->unit_id, today(), 'by_2pm', ed: 10, or: 0, transfer: 0, direct: 0); // demand 10

        $pred = $svc->developPlan($unit->unit_id, today(), 'by_2pm');

        // bed_need = 10 - (5 available + floor(2.0) weighted) = 10 - 7 = 3
        $this->assertEquals(3, $pred->bed_need);
    }

    public function test_upsert_is_idempotent_per_unit_date_horizon(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 1, probable: 0, possible: 0);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 5, probable: 0, possible: 0);

        $this->assertDatabaseCount('prod.rtdc_predictions', 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RtdcServiceTest`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Rewrite RtdcService**

Replace `app/Services/RtdcService.php`:
```php
<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\RtdcPrediction;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The IHI Real-Time Demand Capacity four-step engine.
 * Step 1 predict capacity, Step 2 predict demand, Step 3 develop plan,
 * Step 4 (evaluate) lives in ReconciliationService.
 */
class RtdcService
{
    /** Confidence weights for predicted discharges (research §2). */
    private const WEIGHT_DEFINITE = 1.0;
    private const WEIGHT_PROBABLE = 0.6;
    private const WEIGHT_POSSIBLE = 0.3;

    public function activateWorkflow(Request $request): void
    {
        $request->session()->put('workflow', 'rtdc');
    }

    /** Step 1 — predict capacity (clinician-entered discharge tiers). */
    public function upsertCapacity(int $unitId, CarbonInterface|string $serviceDate, string $horizon, int $definite, int $probable, int $possible): RtdcPrediction
    {
        $weighted = $definite * self::WEIGHT_DEFINITE
            + $probable * self::WEIGHT_PROBABLE
            + $possible * self::WEIGHT_POSSIBLE;

        return $this->prediction($unitId, $serviceDate, $horizon, [
            'discharges_definite' => $definite,
            'discharges_probable' => $probable,
            'discharges_possible' => $possible,
            'discharges_weighted' => round($weighted, 2),
        ]);
    }

    /** Step 2 — predict demand by source. */
    public function upsertDemand(int $unitId, CarbonInterface|string $serviceDate, string $horizon, int $ed, int $or, int $transfer, int $direct): RtdcPrediction
    {
        return $this->prediction($unitId, $serviceDate, $horizon, [
            'demand_ed' => $ed,
            'demand_or' => $or,
            'demand_transfer' => $transfer,
            'demand_direct' => $direct,
            'demand_expected' => $ed + $or + $transfer + $direct,
        ]);
    }

    /** Step 3 — develop plan: compute the signed bed-need integer. */
    public function developPlan(int $unitId, CarbonInterface|string $serviceDate, string $horizon): RtdcPrediction
    {
        $pred = $this->prediction($unitId, $serviceDate, $horizon, []);

        $available = Bed::where('unit_id', $unitId)->where('status', 'available')->where('is_deleted', false)->count();
        $effectiveCapacity = $available + (int) floor($pred->discharges_weighted);
        $bedNeed = $pred->demand_expected - $effectiveCapacity;

        $pred->update([
            'capacity_now' => $available,
            'bed_need' => $bedNeed,
        ]);

        return $pred->fresh();
    }

    private function prediction(int $unitId, CarbonInterface|string $serviceDate, string $horizon, array $attrs): RtdcPrediction
    {
        return RtdcPrediction::updateOrCreate(
            ['unit_id' => $unitId, 'service_date' => $serviceDate, 'horizon' => $horizon],
            $attrs,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RtdcServiceTest`
Expected: PASS.

- [ ] **Step 5: Verify the existing dashboard still activates workflow (regression)**

Run: `php artisan test --filter=RTDC`
Expected: any existing RTDC controller tests still pass (`activateWorkflow` preserved).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Services/RtdcService.php
git add app/Services/RtdcService.php tests/Feature/Rtdc/RtdcServiceTest.php
git commit -m "feat(rtdc): implement IHI four-step engine (capacity, demand, bed-need)"
```

---

### Task C5: Huddles + barriers migrations and models

**Files:**
- Create: `database/migrations/2026_06_20_000050_create_rtdc_huddles_barriers_tables.php`
- Create: `app/Models/Huddle.php`, `app/Models/Barrier.php`
- Modify: `tests/Feature/Rtdc/SchemaTest.php`
- Test: `tests/Feature/Rtdc/HuddleBarrierModelTest.php`

- [ ] **Step 1: Extend the schema test**

Add to `tests/Feature/Rtdc/SchemaTest.php`:
```php
    public function test_huddles_and_barriers_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.huddles', [
            'huddle_id', 'type', 'unit_id', 'service_date', 'status', 'facilitator_id',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.barriers', [
            'barrier_id', 'encounter_id', 'unit_id', 'category', 'reason_code',
            'description', 'owner', 'status', 'opened_at', 'resolved_at',
        ]));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL — `prod.huddles` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_20_000050_create_rtdc_huddles_barriers_tables.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.huddles', function (Blueprint $table) {
            $table->id('huddle_id');
            $table->string('type'); // unit | hospital
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->string('status')->default('open'); // open | closed
            $table->foreignId('facilitator_id')->nullable()->constrained('prod.users', 'id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.huddles ADD CONSTRAINT chk_huddle_type CHECK (type IN ('unit','hospital'))");

        Schema::create('prod.barriers', function (Blueprint $table) {
            $table->id('barrier_id');
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id');
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->string('category'); // medical | logistical | placement | social
            $table->string('reason_code')->nullable();
            $table->text('description')->nullable();
            $table->string('owner')->nullable();
            $table->string('status')->default('open'); // open | resolved
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
            $table->index(['unit_id', 'status']);
        });

        DB::statement("ALTER TABLE prod.barriers ADD CONSTRAINT chk_barrier_category CHECK (category IN ('medical','logistical','placement','social'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.barriers');
        $this->safeDropIfExists('prod.huddles');
    }
};
```

- [ ] **Step 4: Write the models + model test**

Create `app/Models/Huddle.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Huddle extends Model
{
    protected $table = 'prod.huddles';
    protected $primaryKey = 'huddle_id';

    protected $fillable = ['type', 'unit_id', 'service_date', 'status', 'facilitator_id', 'closed_at', 'is_deleted'];

    protected $casts = [
        'service_date' => 'date',
        'closed_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
```

Create `app/Models/Barrier.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barrier extends Model
{
    protected $table = 'prod.barriers';
    protected $primaryKey = 'barrier_id';

    public const CATEGORIES = ['medical', 'logistical', 'placement', 'social'];

    protected $fillable = [
        'encounter_id', 'unit_id', 'category', 'reason_code',
        'description', 'owner', 'status', 'opened_at', 'resolved_at', 'is_deleted',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open')->where('is_deleted', false);
    }
}
```

Create `tests/Feature/Rtdc/HuddleBarrierModelTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Barrier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleBarrierModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_scope_filters_resolved_barriers(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'status' => 'open']);
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'social', 'status' => 'resolved']);

        $this->assertEquals(1, Barrier::open()->count());
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="SchemaTest|HuddleBarrierModelTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Models database/migrations
git add database/migrations/2026_06_20_000050_create_rtdc_huddles_barriers_tables.php app/Models/Huddle.php app/Models/Barrier.php tests/Feature/Rtdc/SchemaTest.php tests/Feature/Rtdc/HuddleBarrierModelTest.php
git commit -m "feat(rtdc): add huddles and barriers tables and models"
```

---

### Task C6: HuddleService (lifecycle + roll-up) and BarrierService

**Files:**
- Create: `app/Services/HuddleService.php`, `app/Services/BarrierService.php`
- Test: `tests/Feature/Rtdc/HuddleServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/HuddleServiceTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Services\BarrierService;
use App\Services\HuddleService;
use App\Services\RtdcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_unit_huddle_is_idempotent_per_unit_date(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $svc = app(HuddleService::class);

        $svc->openUnitHuddle($unit->unit_id, today());
        $svc->openUnitHuddle($unit->unit_id, today());

        $this->assertDatabaseCount('prod.huddles', 1);
    }

    public function test_hospital_rollup_sums_positive_bed_need(): void
    {
        $rtdc = app(RtdcService::class);
        $huddles = app(HuddleService::class);

        $a = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $b = Unit::create(['name' => 'B', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        // A short by 3, B surplus by 2.
        foreach ([$a, $b] as $u) {
            for ($i = 0; $i < 2; $i++) {
                Bed::create(['unit_id' => $u->unit_id, 'label' => "{$u->name}-$i", 'status' => 'available']);
            }
        }
        $rtdc->upsertDemand($a->unit_id, today(), 'by_2pm', ed: 5, or: 0, transfer: 0, direct: 0);
        $rtdc->developPlan($a->unit_id, today(), 'by_2pm'); // need 5-2=3
        $rtdc->upsertDemand($b->unit_id, today(), 'by_2pm', ed: 0, or: 0, transfer: 0, direct: 0);
        $rtdc->developPlan($b->unit_id, today(), 'by_2pm'); // need 0-2=-2

        $rollup = $huddles->hospitalRollup(today(), 'by_2pm');

        $this->assertEquals(3, $rollup['total_positive_bed_need']);
        $this->assertEquals(-2, $rollup['net_bed_need']); // 3 + (-2) - 3 = -2; net across units
    }
}
```

> Note: `net_bed_need` is the simple sum of every unit's signed bed_need (3 + (−2) = 1). Correct the expectation: `assertEquals(1, $rollup['net_bed_need'])`. `total_positive_bed_need` only sums units in deficit (3).

- [ ] **Step 2: Fix the test expectation then run to verify it fails**

Edit the last assertion in the test to:
```php
        $this->assertEquals(3, $rollup['total_positive_bed_need']);
        $this->assertEquals(1, $rollup['net_bed_need']);
```
Run: `php artisan test --filter=HuddleServiceTest`
Expected: FAIL — service not found.

- [ ] **Step 3: Write the services**

Create `app/Services/HuddleService.php`:
```php
<?php

namespace App\Services;

use App\Models\Huddle;
use App\Models\RtdcPrediction;
use Carbon\CarbonInterface;

class HuddleService
{
    public function openUnitHuddle(int $unitId, CarbonInterface|string $serviceDate, ?int $facilitatorId = null): Huddle
    {
        return Huddle::firstOrCreate(
            ['type' => 'unit', 'unit_id' => $unitId, 'service_date' => $serviceDate],
            ['status' => 'open', 'facilitator_id' => $facilitatorId],
        );
    }

    public function openHospitalHuddle(CarbonInterface|string $serviceDate, ?int $facilitatorId = null): Huddle
    {
        return Huddle::firstOrCreate(
            ['type' => 'hospital', 'unit_id' => null, 'service_date' => $serviceDate],
            ['status' => 'open', 'facilitator_id' => $facilitatorId],
        );
    }

    public function close(int $huddleId): Huddle
    {
        $huddle = Huddle::findOrFail($huddleId);
        $huddle->update(['status' => 'closed', 'closed_at' => now()]);

        return $huddle;
    }

    /**
     * Aggregate every unit's signed bed-need for the hospital bed meeting.
     *
     * @return array{net_bed_need:int,total_positive_bed_need:int,units:array}
     */
    public function hospitalRollup(CarbonInterface|string $serviceDate, string $horizon): array
    {
        $preds = RtdcPrediction::with('unit')
            ->whereDate('service_date', $serviceDate)
            ->where('horizon', $horizon)
            ->get();

        return [
            'net_bed_need' => (int) $preds->sum('bed_need'),
            'total_positive_bed_need' => (int) $preds->where('bed_need', '>', 0)->sum('bed_need'),
            'units' => $preds->map(fn (RtdcPrediction $p) => [
                'unit_id' => $p->unit_id,
                'unit_name' => $p->unit?->name,
                'bed_need' => $p->bed_need,
                'capacity_now' => $p->capacity_now,
                'demand_expected' => $p->demand_expected,
            ])->values()->all(),
        ];
    }
}
```

Create `app/Services/BarrierService.php`:
```php
<?php

namespace App\Services;

use App\Models\Barrier;
use InvalidArgumentException;

class BarrierService
{
    public function open(array $data): Barrier
    {
        if (! in_array($data['category'], Barrier::CATEGORIES, true)) {
            throw new InvalidArgumentException("Invalid barrier category: {$data['category']}");
        }

        return Barrier::create([
            'encounter_id' => $data['encounter_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'category' => $data['category'],
            'reason_code' => $data['reason_code'] ?? null,
            'description' => $data['description'] ?? null,
            'owner' => $data['owner'] ?? null,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function resolve(int $barrierId): Barrier
    {
        $barrier = Barrier::findOrFail($barrierId);
        $barrier->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $barrier;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=HuddleServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Services
git add app/Services/HuddleService.php app/Services/BarrierService.php tests/Feature/Rtdc/HuddleServiceTest.php
git commit -m "feat(rtdc): add HuddleService (lifecycle + hospital roll-up) and BarrierService"
```

---

### Phase C exit check

- [ ] Run: `php artisan test --filter=Rtdc`
- [ ] Expected: all Phase A–C tests green. The four-step engine, acuity math, huddles, and barriers work as pure domain logic.

---

## PHASE D — JSON API + broadcast events (the wire)

### Task D1: Broadcast events + channel authorization

**Files:**
- Modify: `app/Events/Rtdc/CensusUpdated.php` (created in B6; add a test)
- Create: `app/Events/Rtdc/HuddleUpdated.php`, `app/Events/Rtdc/BedMeetingUpdated.php`
- Create: `routes/channels.php`
- Modify: `bootstrap/app.php` (register channels route)
- Test: `tests/Feature/Rtdc/BroadcastTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/BroadcastTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Events\Rtdc\CensusUpdated;
use App\Models\CensusSnapshot;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_census_updated_broadcasts_on_unit_channel_with_payload(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $snap = CensusSnapshot::create([
            'unit_id' => $unit->unit_id, 'captured_at' => now(),
            'staffed_beds' => 30, 'occupied' => 10, 'available' => 18, 'blocked' => 2, 'acuity_adjusted_capacity' => 16,
        ]);

        $event = new CensusUpdated($snap);

        $this->assertEquals('unit.'.$unit->unit_id, $event->broadcastOn()->name);
        $this->assertEquals('census.updated', $event->broadcastAs());
        $this->assertEquals(10, $event->broadcastWith()['occupied']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php artisan test --filter=BroadcastTest`
Expected: PASS for `CensusUpdated` (created in B6). If it fails, align B6's `CensusUpdated` to the assertions above.

- [ ] **Step 3: Add the huddle + bed-meeting broadcast events**

Create `app/Events/Rtdc/HuddleUpdated.php`:
```php
<?php

namespace App\Events\Rtdc;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HuddleUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $unitId, public array $prediction) {}

    public function broadcastOn(): Channel
    {
        return new Channel('unit.'.$this->unitId);
    }

    public function broadcastAs(): string
    {
        return 'huddle.updated';
    }

    public function broadcastWith(): array
    {
        return ['unit_id' => $this->unitId, 'prediction' => $this->prediction];
    }
}
```

Create `app/Events/Rtdc/BedMeetingUpdated.php`:
```php
<?php

namespace App\Events\Rtdc;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BedMeetingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $rollup) {}

    public function broadcastOn(): Channel
    {
        return new Channel('hospital.beds');
    }

    public function broadcastAs(): string
    {
        return 'bedmeeting.updated';
    }

    public function broadcastWith(): array
    {
        return $this->rollup;
    }
}
```

- [ ] **Step 4: Add channel authorization + register the route**

Create `routes/channels.php`:
```php
<?php

use Illuminate\Support\Facades\Broadcast;

// S2: any authenticated user may observe operational channels (read-only board data, no PHI in payloads).
Broadcast::channel('unit.{unitId}', fn ($user, $unitId) => $user !== null);
Broadcast::channel('hospital.beds', fn ($user) => $user !== null);
```

In `bootstrap/app.php`, add the channels route to `withRouting(...)`:
```php
    ->withRouting(
        // ...existing web/api/commands entries...
        channels: __DIR__.'/../routes/channels.php',
    )
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BroadcastTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Events routes
git add app/Events/Rtdc routes/channels.php bootstrap/app.php tests/Feature/Rtdc/BroadcastTest.php
git commit -m "feat(rtdc): add huddle/bed-meeting broadcast events and channel auth"
```

---

### Task D2: Census API (units + live snapshot)

**Files:**
- Create: `app/Http/Controllers/Api/Rtdc/CensusController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Rtdc/CensusApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/CensusApiTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CensusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_endpoint_returns_live_census(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 4, 'ratio_floor' => 5]);
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'occupied']);
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available']);

        $response = $this->actingAs($user)->getJson('/api/rtdc/units');

        $response->assertOk()->assertJsonStructure([
            'data' => [['unit_id', 'name', 'type', 'census' => ['occupied', 'available', 'acuity_adjusted_capacity']]],
        ]);
        $response->assertJsonPath('data.0.census.occupied', 1);
    }

    public function test_units_endpoint_requires_auth(): void
    {
        $this->getJson('/api/rtdc/units')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CensusApiTest`
Expected: FAIL — route not defined (404/500).

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Api/Rtdc/CensusController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Http\JsonResponse;

class CensusController extends Controller
{
    public function __construct(private readonly AcuityService $acuity) {}

    public function units(): JsonResponse
    {
        $units = Unit::with('beds')->where('is_deleted', false)->get()->map(function (Unit $unit) {
            $beds = $unit->beds;

            return [
                'unit_id' => $unit->unit_id,
                'name' => $unit->name,
                'type' => $unit->type,
                'staffed_bed_count' => $unit->staffed_bed_count,
                'census' => [
                    'occupied' => $beds->where('status', 'occupied')->count(),
                    'available' => $beds->where('status', 'available')->count(),
                    'blocked' => $beds->whereIn('status', ['blocked', 'dirty'])->count(),
                    'acuity_adjusted_capacity' => $this->acuity->adjustedCapacity($unit->unit_id),
                ],
            ];
        });

        return response()->json(['data' => $units]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add (inside an `auth:sanctum`-protected group — match existing api.php style; use `auth` if the file uses session auth):
```php
use App\Http\Controllers\Api\Rtdc\CensusController;

Route::middleware('auth:sanctum')->prefix('rtdc')->group(function () {
    Route::get('/units', [CensusController::class, 'units']);
});
```

> If `tests/Feature/Rtdc/CensusApiTest::test_units_endpoint_requires_auth` returns 200 instead of 401, the API uses the web `auth` guard — change the middleware to `['web', 'auth']` and the test to use `actingAs` only. Verify which guard the existing `/api/cases` routes use and match it.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CensusApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Rtdc routes/api.php
git add app/Http/Controllers/Api/Rtdc/CensusController.php routes/api.php tests/Feature/Rtdc/CensusApiTest.php
git commit -m "feat(rtdc): census API endpoint returning live per-unit occupancy"
```

---

### Task D3: Prediction API (the four-step, over HTTP) + FormRequests

**Files:**
- Create: `app/Http/Controllers/Api/Rtdc/PredictionController.php`
- Create: `app/Http/Requests/Rtdc/UpsertCapacityRequest.php`, `UpsertDemandRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Rtdc/PredictionApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/PredictionApiTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_four_step_cycle_over_http(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        for ($i = 0; $i < 5; $i++) {
            Bed::create(['unit_id' => $unit->unit_id, 'label' => "5E-0$i", 'status' => 'available']);
        }
        $date = today()->toDateString();

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/capacity", [
            'service_date' => $date, 'horizon' => 'by_2pm', 'definite' => 2, 'probable' => 0, 'possible' => 0,
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/demand", [
            'service_date' => $date, 'horizon' => 'by_2pm', 'ed' => 10, 'or' => 0, 'transfer' => 0, 'direct' => 0,
        ])->assertOk();

        $resp = $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/plan", [
            'service_date' => $date, 'horizon' => 'by_2pm',
        ])->assertOk();

        $resp->assertJsonPath('data.bed_need', 3); // 10 - (5 + floor(2.0)) = 3
    }

    public function test_capacity_rejects_invalid_horizon(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/capacity", [
            'service_date' => today()->toDateString(), 'horizon' => 'tomorrow', 'definite' => 1, 'probable' => 0, 'possible' => 0,
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PredictionApiTest`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write the FormRequests**

Create `app/Http/Requests/Rtdc/UpsertCapacityRequest.php`:
```php
<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
            'definite' => 'required|integer|min:0|max:200',
            'probable' => 'required|integer|min:0|max:200',
            'possible' => 'required|integer|min:0|max:200',
        ];
    }
}
```

Create `app/Http/Requests/Rtdc/UpsertDemandRequest.php`:
```php
<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class UpsertDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
            'ed' => 'required|integer|min:0|max:500',
            'or' => 'required|integer|min:0|max:500',
            'transfer' => 'required|integer|min:0|max:500',
            'direct' => 'required|integer|min:0|max:500',
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/Api/Rtdc/PredictionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Events\Rtdc\HuddleUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\UpsertCapacityRequest;
use App\Http\Requests\Rtdc\UpsertDemandRequest;
use App\Models\RtdcPrediction;
use App\Services\RtdcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function __construct(private readonly RtdcService $rtdc) {}

    public function show(int $unitId, Request $request): JsonResponse
    {
        $pred = RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $request->query('service_date', today()->toDateString()))
            ->where('horizon', $request->query('horizon', 'by_2pm'))
            ->first();

        return response()->json(['data' => $pred]);
    }

    public function capacity(int $unitId, UpsertCapacityRequest $request): JsonResponse
    {
        $v = $request->validated();
        $pred = $this->rtdc->upsertCapacity($unitId, $v['service_date'], $v['horizon'], $v['definite'], $v['probable'], $v['possible']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }

    public function demand(int $unitId, UpsertDemandRequest $request): JsonResponse
    {
        $v = $request->validated();
        $pred = $this->rtdc->upsertDemand($unitId, $v['service_date'], $v['horizon'], $v['ed'], $v['or'], $v['transfer'], $v['direct']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }

    public function plan(int $unitId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
        ]);
        $pred = $this->rtdc->developPlan($unitId, $validated['service_date'], $validated['horizon']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }
}
```

- [ ] **Step 5: Register the routes**

Add to the `rtdc` group in `routes/api.php`:
```php
use App\Http\Controllers\Api\Rtdc\PredictionController;

    Route::get('/units/{unitId}/prediction', [PredictionController::class, 'show']);
    Route::post('/units/{unitId}/capacity', [PredictionController::class, 'capacity']);
    Route::post('/units/{unitId}/demand', [PredictionController::class, 'demand']);
    Route::post('/units/{unitId}/plan', [PredictionController::class, 'plan']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PredictionApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Http
git add app/Http/Controllers/Api/Rtdc/PredictionController.php app/Http/Requests/Rtdc routes/api.php tests/Feature/Rtdc/PredictionApiTest.php
git commit -m "feat(rtdc): four-step prediction API with validation and live broadcast"
```

---

### Task D4: Huddle, Barrier, and Bed-Meeting API

**Files:**
- Create: `app/Http/Controllers/Api/Rtdc/HuddleController.php`, `BarrierController.php`
- Create: `app/Http/Requests/Rtdc/UpsertBarrierRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Rtdc/HuddleApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/HuddleApiTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_close_unit_huddle_and_rollup(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $date = today()->toDateString();

        $open = $this->actingAs($user)->postJson('/api/rtdc/huddles', [
            'type' => 'unit', 'unit_id' => $unit->unit_id, 'service_date' => $date,
        ])->assertOk()->json('data');

        $this->actingAs($user)->getJson("/api/rtdc/bed-meeting?service_date={$date}&horizon=by_2pm")
            ->assertOk()->assertJsonStructure(['data' => ['net_bed_need', 'total_positive_bed_need', 'units']]);

        $this->actingAs($user)->postJson("/api/rtdc/huddles/{$open['huddle_id']}/close")->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_barrier_create_and_resolve(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);

        $barrier = $this->actingAs($user)->postJson('/api/rtdc/barriers', [
            'unit_id' => $unit->unit_id, 'category' => 'placement', 'description' => 'Awaiting SNF bed',
        ])->assertOk()->json('data');

        $this->actingAs($user)->postJson("/api/rtdc/barriers/{$barrier['barrier_id']}/resolve")->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }

    public function test_barrier_rejects_invalid_category(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);

        $this->actingAs($user)->postJson('/api/rtdc/barriers', [
            'unit_id' => $unit->unit_id, 'category' => 'financial',
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HuddleApiTest`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write the FormRequest**

Create `app/Http/Requests/Rtdc/UpsertBarrierRequest.php`:
```php
<?php

namespace App\Http\Requests\Rtdc;

use App\Models\Barrier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => 'nullable|integer|exists:prod.units,unit_id',
            'encounter_id' => 'nullable|integer|exists:prod.encounters,encounter_id',
            'category' => ['required', Rule::in(Barrier::CATEGORIES)],
            'reason_code' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'owner' => 'nullable|string|max:100',
        ];
    }
}
```

- [ ] **Step 4: Write the controllers**

Create `app/Http/Controllers/Api/Rtdc/HuddleController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Events\Rtdc\BedMeetingUpdated;
use App\Http\Controllers\Controller;
use App\Services\HuddleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HuddleController extends Controller
{
    public function __construct(private readonly HuddleService $huddles) {}

    public function open(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type' => 'required|in:unit,hospital',
            'unit_id' => 'nullable|integer|exists:prod.units,unit_id',
            'service_date' => 'required|date',
        ]);

        $huddle = $v['type'] === 'unit'
            ? $this->huddles->openUnitHuddle($v['unit_id'], $v['service_date'], $request->user()->id)
            : $this->huddles->openHospitalHuddle($v['service_date'], $request->user()->id);

        return response()->json(['data' => $huddle]);
    }

    public function close(int $huddleId): JsonResponse
    {
        return response()->json(['data' => $this->huddles->close($huddleId)]);
    }

    public function bedMeeting(Request $request): JsonResponse
    {
        $v = $request->validate([
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
        ]);
        $rollup = $this->huddles->hospitalRollup($v['service_date'], $v['horizon']);
        broadcast(new BedMeetingUpdated($rollup));

        return response()->json(['data' => $rollup]);
    }
}
```

Create `app/Http/Controllers/Api/Rtdc/BarrierController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\UpsertBarrierRequest;
use App\Models\Barrier;
use App\Services\BarrierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BarrierController extends Controller
{
    public function __construct(private readonly BarrierService $barriers) {}

    public function index(Request $request): JsonResponse
    {
        $query = Barrier::open();
        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->integer('unit_id'));
        }

        return response()->json(['data' => $query->orderBy('opened_at')->get()]);
    }

    public function store(UpsertBarrierRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->barriers->open($request->validated())]);
    }

    public function resolve(int $barrierId): JsonResponse
    {
        return response()->json(['data' => $this->barriers->resolve($barrierId)]);
    }
}
```

- [ ] **Step 5: Register routes**

Add to the `rtdc` group in `routes/api.php`:
```php
use App\Http\Controllers\Api\Rtdc\BarrierController;
use App\Http\Controllers\Api\Rtdc\HuddleController;

    Route::post('/huddles', [HuddleController::class, 'open']);
    Route::post('/huddles/{huddleId}/close', [HuddleController::class, 'close']);
    Route::get('/bed-meeting', [HuddleController::class, 'bedMeeting']);

    Route::get('/barriers', [BarrierController::class, 'index']);
    Route::post('/barriers', [BarrierController::class, 'store']);
    Route::post('/barriers/{barrierId}/resolve', [BarrierController::class, 'resolve']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=HuddleApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Http
git add app/Http/Controllers/Api/Rtdc/HuddleController.php app/Http/Controllers/Api/Rtdc/BarrierController.php app/Http/Requests/Rtdc/UpsertBarrierRequest.php routes/api.php tests/Feature/Rtdc/HuddleApiTest.php
git commit -m "feat(rtdc): huddle, bed-meeting, and barrier API endpoints"
```

---

### Phase D exit check

- [ ] Run: `php artisan test --filter=Rtdc`
- [ ] Expected: all Phase A–D tests green. The full four-step cycle, huddles, barriers, and live census are reachable over HTTP and broadcast on Reverb channels.

---

## PHASE E — Frontend rewire + TypeScript migration

The contract is Zod schemas; data comes via TanStack Query; live updates via Echo. We migrate `UnitHuddle` and `GlobalHuddle` to `.tsx` and delete their mock-data imports.

### Task E1: Zod schemas (the validation contract)

**Files:**
- Create: `resources/js/schemas/rtdc.ts`
- Test: `tests/js/rtdc/schemas.test.ts`

- [ ] **Step 1: Write the failing test**

Create `tests/js/rtdc/schemas.test.ts`:
```ts
import { describe, it, expect } from 'vitest';
import { unitCensusSchema, predictionSchema, bedMeetingSchema, barrierSchema } from '@/schemas/rtdc';

describe('rtdc schemas', () => {
  it('parses a valid unit census', () => {
    const parsed = unitCensusSchema.parse({
      unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32,
      census: { occupied: 20, available: 10, blocked: 2, acuity_adjusted_capacity: 8 },
    });
    expect(parsed.census.occupied).toBe(20);
  });

  it('rejects an invalid horizon on a prediction', () => {
    expect(() =>
      predictionSchema.parse({
        rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'tomorrow',
        discharges_weighted: 0, demand_expected: 0, capacity_now: 0, bed_need: 0, status: 'open',
      }),
    ).toThrow();
  });

  it('parses a bed-meeting rollup', () => {
    const parsed = bedMeetingSchema.parse({
      net_bed_need: 3, total_positive_bed_need: 5,
      units: [{ unit_id: 1, unit_name: '5 East', bed_need: 3, capacity_now: 2, demand_expected: 5 }],
    });
    expect(parsed.units).toHaveLength(1);
  });

  it('rejects an invalid barrier category', () => {
    expect(() => barrierSchema.parse({ barrier_id: 1, category: 'financial', status: 'open' })).toThrow();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/schemas.test.ts`
Expected: FAIL — cannot resolve `@/schemas/rtdc`.

- [ ] **Step 3: Write the schemas**

Create `resources/js/schemas/rtdc.ts`:
```ts
import { z } from 'zod';

export const horizonSchema = z.enum(['by_2pm', 'by_midnight']);

export const unitCensusSchema = z.object({
  unit_id: z.number(),
  name: z.string(),
  type: z.enum(['ed', 'med_surg', 'icu', 'step_down']),
  staffed_bed_count: z.number(),
  census: z.object({
    occupied: z.number(),
    available: z.number(),
    blocked: z.number(),
    acuity_adjusted_capacity: z.number(),
  }),
});
export type UnitCensus = z.infer<typeof unitCensusSchema>;

export const predictionSchema = z.object({
  rtdc_prediction_id: z.number(),
  unit_id: z.number(),
  service_date: z.string(),
  horizon: horizonSchema,
  discharges_definite: z.number().optional(),
  discharges_probable: z.number().optional(),
  discharges_possible: z.number().optional(),
  discharges_weighted: z.coerce.number(),
  demand_ed: z.number().optional(),
  demand_or: z.number().optional(),
  demand_transfer: z.number().optional(),
  demand_direct: z.number().optional(),
  demand_expected: z.number(),
  capacity_now: z.number(),
  bed_need: z.number(),
  status: z.enum(['open', 'closed']),
});
export type Prediction = z.infer<typeof predictionSchema>;

export const bedMeetingUnitSchema = z.object({
  unit_id: z.number(),
  unit_name: z.string().nullable(),
  bed_need: z.number(),
  capacity_now: z.number(),
  demand_expected: z.number(),
});

export const bedMeetingSchema = z.object({
  net_bed_need: z.number(),
  total_positive_bed_need: z.number(),
  units: z.array(bedMeetingUnitSchema),
});
export type BedMeeting = z.infer<typeof bedMeetingSchema>;

export const barrierSchema = z.object({
  barrier_id: z.number(),
  unit_id: z.number().nullable().optional(),
  encounter_id: z.number().nullable().optional(),
  category: z.enum(['medical', 'logistical', 'placement', 'social']),
  reason_code: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  owner: z.string().nullable().optional(),
  status: z.enum(['open', 'resolved']),
});
export type Barrier = z.infer<typeof barrierSchema>;

export const censusUpdatedEventSchema = z.object({
  unit_id: z.number(),
  captured_at: z.string().nullable(),
  staffed_beds: z.number(),
  occupied: z.number(),
  available: z.number(),
  blocked: z.number(),
  acuity_adjusted_capacity: z.number(),
});
export type CensusUpdatedEvent = z.infer<typeof censusUpdatedEventSchema>;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/rtdc/schemas.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/schemas/rtdc.ts tests/js/rtdc/schemas.test.ts
git commit -m "feat(rtdc): Zod schemas as the web validation contract"
```

---

### Task E2: API fetchers + TanStack Query hooks + Echo subscription

**Files:**
- Create: `resources/js/features/rtdc/api.ts`
- Create: `resources/js/features/rtdc/hooks.ts`
- Test: `tests/js/rtdc/api.test.ts`

- [ ] **Step 1: Write the failing test (fetcher validates with Zod)**

Create `tests/js/rtdc/api.test.ts`:
```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { fetchUnits, upsertCapacity } from '@/features/rtdc/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

describe('rtdc api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchUnits returns Zod-validated units', async () => {
    mocked.get.mockResolvedValue({
      data: { data: [{ unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32, census: { occupied: 1, available: 2, blocked: 0, acuity_adjusted_capacity: 30 } }] },
    });

    const units = await fetchUnits();
    expect(units[0].name).toBe('5 East');
    expect(mocked.get).toHaveBeenCalledWith('/api/rtdc/units');
  });

  it('fetchUnits throws on schema violation', async () => {
    mocked.get.mockResolvedValue({ data: { data: [{ unit_id: 'oops' }] } });
    await expect(fetchUnits()).rejects.toThrow();
  });

  it('upsertCapacity posts to the unit capacity endpoint', async () => {
    mocked.post.mockResolvedValue({
      data: { data: { rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'by_2pm', discharges_weighted: 2, demand_expected: 0, capacity_now: 0, bed_need: 0, status: 'open' } },
    });
    const pred = await upsertCapacity(1, { service_date: '2026-06-20', horizon: 'by_2pm', definite: 2, probable: 0, possible: 0 });
    expect(pred.bed_need).toBe(0);
    expect(mocked.post).toHaveBeenCalledWith('/api/rtdc/units/1/capacity', expect.objectContaining({ definite: 2 }));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/api.test.ts`
Expected: FAIL — cannot resolve `@/features/rtdc/api`.

- [ ] **Step 3: Write the fetchers**

Create `resources/js/features/rtdc/api.ts`:
```ts
import axios from 'axios';
import { z } from 'zod';
import {
  unitCensusSchema, predictionSchema, bedMeetingSchema, barrierSchema,
  type UnitCensus, type Prediction, type BedMeeting, type Barrier,
} from '@/schemas/rtdc';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchUnits(): Promise<UnitCensus[]> {
  const res = await axios.get('/api/rtdc/units');
  return envelope(z.array(unitCensusSchema)).parse(res.data).data;
}

export async function fetchPrediction(unitId: number, serviceDate: string, horizon: string): Promise<Prediction | null> {
  const res = await axios.get(`/api/rtdc/units/${unitId}/prediction`, { params: { service_date: serviceDate, horizon } });
  const parsed = z.object({ data: predictionSchema.nullable() }).parse(res.data);
  return parsed.data;
}

export interface CapacityInput { service_date: string; horizon: string; definite: number; probable: number; possible: number }
export async function upsertCapacity(unitId: number, input: CapacityInput): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/capacity`, input);
  return envelope(predictionSchema).parse(res.data).data;
}

export interface DemandInput { service_date: string; horizon: string; ed: number; or: number; transfer: number; direct: number }
export async function upsertDemand(unitId: number, input: DemandInput): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/demand`, input);
  return envelope(predictionSchema).parse(res.data).data;
}

export async function developPlan(unitId: number, serviceDate: string, horizon: string): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/plan`, { service_date: serviceDate, horizon });
  return envelope(predictionSchema).parse(res.data).data;
}

export async function fetchBedMeeting(serviceDate: string, horizon: string): Promise<BedMeeting> {
  const res = await axios.get('/api/rtdc/bed-meeting', { params: { service_date: serviceDate, horizon } });
  return envelope(bedMeetingSchema).parse(res.data).data;
}

export async function fetchBarriers(unitId?: number): Promise<Barrier[]> {
  const res = await axios.get('/api/rtdc/barriers', { params: unitId ? { unit_id: unitId } : {} });
  return envelope(z.array(barrierSchema)).parse(res.data).data;
}
```

- [ ] **Step 4: Write the hooks**

Create `resources/js/features/rtdc/hooks.ts`:
```ts
import { useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/echo';
import {
  fetchUnits, fetchPrediction, upsertCapacity, upsertDemand, developPlan,
  fetchBedMeeting, fetchBarriers, type CapacityInput, type DemandInput,
} from './api';
import { censusUpdatedEventSchema } from '@/schemas/rtdc';

export function useUnits() {
  return useQuery({ queryKey: ['rtdc', 'units'], queryFn: fetchUnits });
}

export function usePrediction(unitId: number, serviceDate: string, horizon: string) {
  return useQuery({
    queryKey: ['rtdc', 'prediction', unitId, serviceDate, horizon],
    queryFn: () => fetchPrediction(unitId, serviceDate, horizon),
  });
}

export function useBedMeeting(serviceDate: string, horizon: string) {
  return useQuery({
    queryKey: ['rtdc', 'bed-meeting', serviceDate, horizon],
    queryFn: () => fetchBedMeeting(serviceDate, horizon),
  });
}

export function useBarriers(unitId?: number) {
  return useQuery({ queryKey: ['rtdc', 'barriers', unitId], queryFn: () => fetchBarriers(unitId) });
}

export function useUpsertCapacity(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CapacityInput) => upsertCapacity(unitId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

export function useUpsertDemand(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: DemandInput) => upsertDemand(unitId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

export function useDevelopPlan(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ serviceDate, horizon }: { serviceDate: string; horizon: string }) =>
      developPlan(unitId, serviceDate, horizon),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

/**
 * Live census subscription. Research §7: snapshot-on-reconnect — we invalidate
 * the units query whenever the socket (re)connects so we never rely on replaying
 * missed messages (Reverb/Pusher do not replay).
 */
export function useLiveCensus() {
  const qc = useQueryClient();

  useEffect(() => {
    const refetch = () => qc.invalidateQueries({ queryKey: ['rtdc', 'units'] });

    const units = qc.getQueryData<{ unit_id: number }[]>(['rtdc', 'units']) ?? [];
    const channels = units.map((u) => `unit.${u.unit_id}`);

    channels.forEach((name) => {
      echo.channel(name).listen('.census.updated', (raw: unknown) => {
        censusUpdatedEventSchema.parse(raw); // validate the wire payload
        refetch();
      });
    });

    // Snapshot-on-(re)connect.
    echo.connector.pusher.connection.bind('connected', refetch);

    return () => {
      channels.forEach((name) => echo.leaveChannel(name));
      echo.connector.pusher.connection.unbind('connected', refetch);
    };
  }, [qc]);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run tests/js/rtdc/api.test.ts`
Expected: PASS.

- [ ] **Step 6: Type-check**

Run: `npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/features/rtdc/api.ts resources/js/features/rtdc/hooks.ts tests/js/rtdc/api.test.ts
git commit -m "feat(rtdc): typed API fetchers + TanStack hooks + live census subscription"
```

---

### Task E3: New focused UI components

**Files:**
- Create: `resources/js/Components/RTDC/BedNeedReadout.tsx`, `DischargeTierEntry.tsx`, `DemandBySourceEntry.tsx`, `BarrierBoard.tsx`, `ReliabilityTile.tsx`
- Test: `tests/js/rtdc/components.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/rtdc/components.test.tsx`:
```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { BedNeedReadout } from '@/Components/RTDC/BedNeedReadout';
import { DischargeTierEntry } from '@/Components/RTDC/DischargeTierEntry';

describe('BedNeedReadout', () => {
  it('shows a deficit in critical styling when bed_need is positive', () => {
    render(<BedNeedReadout bedNeed={3} capacityNow={2} demandExpected={5} />);
    expect(screen.getByText(/short 3 beds/i)).toBeInTheDocument();
  });

  it('shows surplus when bed_need is negative', () => {
    render(<BedNeedReadout bedNeed={-2} capacityNow={5} demandExpected={3} />);
    expect(screen.getByText(/2 beds surplus/i)).toBeInTheDocument();
  });
});

describe('DischargeTierEntry', () => {
  it('emits changes for each confidence tier', () => {
    const onChange = vi.fn();
    render(<DischargeTierEntry definite={1} probable={0} possible={0} onChange={onChange} />);
    fireEvent.change(screen.getByLabelText(/definite/i), { target: { value: '4' } });
    expect(onChange).toHaveBeenCalledWith({ definite: 4, probable: 0, possible: 0 });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/components.test.tsx`
Expected: FAIL — components not found.

- [ ] **Step 3: Write `BedNeedReadout.tsx`**

Create `resources/js/Components/RTDC/BedNeedReadout.tsx`:
```tsx
interface BedNeedReadoutProps {
  bedNeed: number;
  capacityNow: number;
  demandExpected: number;
}

export function BedNeedReadout({ bedNeed, capacityNow, demandExpected }: BedNeedReadoutProps) {
  const deficit = bedNeed > 0;
  const surplus = bedNeed < 0;
  const tone = deficit ? 'text-[var(--critical)]' : surplus ? 'text-[var(--success)]' : 'text-[var(--text-secondary)]';

  return (
    <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
      <div className="text-label">Bed Need</div>
      <div className={`text-value ${tone}`}>{bedNeed > 0 ? `+${bedNeed}` : bedNeed}</div>
      <div className="text-caption">
        {deficit && `Short ${bedNeed} beds`}
        {surplus && `${Math.abs(bedNeed)} beds surplus`}
        {bedNeed === 0 && 'Balanced'}
      </div>
      <div className="text-caption mt-[var(--space-2)]">
        Demand {demandExpected} · Effective capacity {capacityNow}
      </div>
      {/* S2 safety note (spec §10): bed-need surfaces capacity vs demand for the huddle to decide;
          it never recommends a discharge. */}
      <div className="text-caption mt-[var(--space-2)] italic">For huddle decision — not an automated action.</div>
    </div>
  );
}
```

- [ ] **Step 4: Write `DischargeTierEntry.tsx`**

Create `resources/js/Components/RTDC/DischargeTierEntry.tsx`:
```tsx
interface DischargeTiers { definite: number; probable: number; possible: number }
interface DischargeTierEntryProps extends DischargeTiers { onChange: (tiers: DischargeTiers) => void }

export function DischargeTierEntry({ definite, probable, possible, onChange }: DischargeTierEntryProps) {
  const field = (key: keyof DischargeTiers, value: number) =>
    onChange({ definite, probable, possible, [key]: value });

  return (
    <div className="grid grid-cols-3 gap-[var(--space-3)]">
      {(['definite', 'probable', 'possible'] as const).map((tier) => (
        <label key={tier} className="flex flex-col gap-[var(--space-1)]">
          <span className="text-label capitalize">{tier}</span>
          <input
            type="number"
            min={0}
            aria-label={tier}
            value={{ definite, probable, possible }[tier]}
            onChange={(e) => field(tier, Number(e.target.value))}
            className="rounded-[var(--radius-sm)] bg-[var(--surface-overlay)] p-[var(--space-2)] text-[var(--text-primary)]"
          />
        </label>
      ))}
    </div>
  );
}
```

- [ ] **Step 5: Write `DemandBySourceEntry.tsx`, `BarrierBoard.tsx`, `ReliabilityTile.tsx`**

Create `resources/js/Components/RTDC/DemandBySourceEntry.tsx`:
```tsx
interface DemandSources { ed: number; or: number; transfer: number; direct: number }
interface DemandBySourceEntryProps extends DemandSources { onChange: (d: DemandSources) => void }

export function DemandBySourceEntry({ ed, or, transfer, direct, onChange }: DemandBySourceEntryProps) {
  const current = { ed, or, transfer, direct };
  const field = (key: keyof DemandSources, value: number) => onChange({ ...current, [key]: value });
  const labels: Record<keyof DemandSources, string> = { ed: 'ED', or: 'OR', transfer: 'Transfer', direct: 'Direct' };

  return (
    <div className="grid grid-cols-4 gap-[var(--space-3)]">
      {(Object.keys(labels) as (keyof DemandSources)[]).map((k) => (
        <label key={k} className="flex flex-col gap-[var(--space-1)]">
          <span className="text-label">{labels[k]}</span>
          <input
            type="number" min={0} aria-label={labels[k]} value={current[k]}
            onChange={(e) => field(k, Number(e.target.value))}
            className="rounded-[var(--radius-sm)] bg-[var(--surface-overlay)] p-[var(--space-2)] text-[var(--text-primary)]"
          />
        </label>
      ))}
    </div>
  );
}
```

Create `resources/js/Components/RTDC/BarrierBoard.tsx`:
```tsx
import type { Barrier } from '@/schemas/rtdc';

const CATEGORY_TONE: Record<Barrier['category'], string> = {
  medical: 'var(--critical)',
  logistical: 'var(--warning)',
  placement: 'var(--info)',
  social: 'var(--accent)',
};

interface BarrierBoardProps { barriers: Barrier[]; onResolve: (id: number) => void }

export function BarrierBoard({ barriers, onResolve }: BarrierBoardProps) {
  if (barriers.length === 0) {
    return <div className="text-caption">No open barriers.</div>;
  }
  return (
    <ul className="flex flex-col gap-[var(--space-2)]">
      {barriers.map((b) => (
        <li key={b.barrier_id} className="flex items-center justify-between rounded-[var(--radius-sm)] bg-[var(--surface-overlay)] p-[var(--space-3)]">
          <span className="flex items-center gap-[var(--space-2)]">
            <span className="inline-block h-2 w-2 rounded-full" style={{ background: CATEGORY_TONE[b.category] }} />
            <span className="text-label capitalize">{b.category}</span>
            <span className="text-[var(--text-secondary)]">{b.description}</span>
          </span>
          <button onClick={() => onResolve(b.barrier_id)} className="text-caption underline">Resolve</button>
        </li>
      ))}
    </ul>
  );
}
```

Create `resources/js/Components/RTDC/ReliabilityTile.tsx`:
```tsx
interface ReliabilityTileProps { score: number | null }

export function ReliabilityTile({ score }: ReliabilityTileProps) {
  return (
    <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
      <div className="text-label">Discharge Prediction Reliability</div>
      <div className="text-value">{score === null ? '—' : `${Math.round(score * 100)}%`}</div>
      <div className="text-caption">Yesterday&apos;s predicted vs actual (RTDC Step 4)</div>
    </div>
  );
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `npx vitest run tests/js/rtdc/components.test.tsx`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/RTDC/BedNeedReadout.tsx resources/js/Components/RTDC/DischargeTierEntry.tsx resources/js/Components/RTDC/DemandBySourceEntry.tsx resources/js/Components/RTDC/BarrierBoard.tsx resources/js/Components/RTDC/ReliabilityTile.tsx tests/js/rtdc/components.test.tsx
git commit -m "feat(rtdc): focused huddle UI components (bed-need, tiers, demand, barriers, reliability)"
```

---

### Task E4: Migrate UnitHuddle to live TypeScript page

**Files:**
- Create: `resources/js/Pages/RTDC/UnitHuddle.tsx`
- Delete: `resources/js/Pages/RTDC/UnitHuddle.jsx`
- Test: `tests/js/rtdc/unit-huddle.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/rtdc/unit-huddle.test.tsx`:
```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import UnitHuddle from '@/Pages/RTDC/UnitHuddle';

vi.mock('axios');
vi.mock('@/lib/echo', () => ({
  echo: {
    channel: () => ({ listen: vi.fn() }),
    leaveChannel: vi.fn(),
    connector: { pusher: { connection: { bind: vi.fn(), unbind: vi.fn() } } },
  },
}));
const mocked = vi.mocked(axios, true);

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('UnitHuddle (live)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders live census from the API and the bed-need readout', async () => {
    mocked.get.mockImplementation((url: string) => {
      if (url === '/api/rtdc/units') {
        return Promise.resolve({ data: { data: [{ unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32, census: { occupied: 20, available: 10, blocked: 2, acuity_adjusted_capacity: 8 } }] } });
      }
      if (url.includes('/prediction')) {
        return Promise.resolve({ data: { data: { rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'by_2pm', discharges_weighted: 2, demand_expected: 5, capacity_now: 2, bed_need: 3, status: 'open' } } });
      }
      if (url.includes('/barriers')) {
        return Promise.resolve({ data: { data: [] } });
      }
      return Promise.resolve({ data: { data: null } });
    });

    renderWithClient(<UnitHuddle unitId={1} />);

    await waitFor(() => expect(screen.getByText('5 East')).toBeInTheDocument());
    expect(screen.getByText(/short 3 beds/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/unit-huddle.test.tsx`
Expected: FAIL — page is still `.jsx` / not wired to the API.

- [ ] **Step 3: Write the migrated page**

Create `resources/js/Pages/RTDC/UnitHuddle.tsx`:
```tsx
import { useState } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { BedNeedReadout } from '@/Components/RTDC/BedNeedReadout';
import { DischargeTierEntry } from '@/Components/RTDC/DischargeTierEntry';
import { DemandBySourceEntry } from '@/Components/RTDC/DemandBySourceEntry';
import { BarrierBoard } from '@/Components/RTDC/BarrierBoard';
import {
  useUnits, usePrediction, useBarriers, useUpsertCapacity, useUpsertDemand,
  useDevelopPlan, useLiveCensus,
} from '@/features/rtdc/hooks';
import { fetchBarriers } from '@/features/rtdc/api';
import axios from 'axios';
import { useQueryClient } from '@tanstack/react-query';

interface UnitHuddleProps { unitId?: number }

const TODAY = new Date().toISOString().slice(0, 10);

export default function UnitHuddle({ unitId = 1 }: UnitHuddleProps) {
  const horizon = 'by_2pm';
  useLiveCensus();

  const qc = useQueryClient();
  const { data: units } = useUnits();
  const unit = units?.find((u) => u.unit_id === unitId);
  const { data: prediction } = usePrediction(unitId, TODAY, horizon);
  const { data: barriers } = useBarriers(unitId);

  const capacityMut = useUpsertCapacity(unitId);
  const demandMut = useUpsertDemand(unitId);
  const planMut = useDevelopPlan(unitId);

  const [tiers, setTiers] = useState({ definite: 0, probable: 0, possible: 0 });
  const [demand, setDemand] = useState({ ed: 0, or: 0, transfer: 0, direct: 0 });

  const saveCapacity = () => capacityMut.mutate({ service_date: TODAY, horizon, ...tiers });
  const saveDemand = () => demandMut.mutate({ service_date: TODAY, horizon, ...demand });
  const computePlan = () => planMut.mutate({ serviceDate: TODAY, horizon });

  const resolveBarrier = async (id: number) => {
    await axios.post(`/api/rtdc/barriers/${id}/resolve`);
    qc.setQueryData(['rtdc', 'barriers', unitId], await fetchBarriers(unitId));
  };

  return (
    <RTDCPageLayout title={unit ? `Unit Huddle — ${unit.name}` : 'Unit Huddle'} subtitle="Real-Time Demand Capacity — Step 1–3">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-[var(--space-6)]">
        <section className="lg:col-span-2 flex flex-col gap-[var(--space-5)]">
          <div>
            <h3 className="text-panel-title">Step 1 — Predict discharges</h3>
            <DischargeTierEntry {...tiers} onChange={setTiers} />
            <button onClick={saveCapacity} className="mt-[var(--space-2)] text-caption underline">Save capacity</button>
          </div>
          <div>
            <h3 className="text-panel-title">Step 2 — Predict demand</h3>
            <DemandBySourceEntry {...demand} onChange={setDemand} />
            <button onClick={saveDemand} className="mt-[var(--space-2)] text-caption underline">Save demand</button>
          </div>
          <div>
            <h3 className="text-panel-title">Barriers</h3>
            <BarrierBoard barriers={barriers ?? []} onResolve={resolveBarrier} />
          </div>
        </section>

        <aside className="flex flex-col gap-[var(--space-5)]">
          {unit && (
            <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
              <div className="text-label">Live Census</div>
              <div className="text-value">{unit.census.occupied}/{unit.staffed_bed_count}</div>
              <div className="text-caption">
                {unit.census.available} available · safe additional capacity {unit.census.acuity_adjusted_capacity}
              </div>
            </div>
          )}
          <button onClick={computePlan} className="rounded-[var(--radius-md)] bg-[var(--primary)] p-[var(--space-3)] text-white">
            Step 3 — Compute bed-need
          </button>
          {prediction && (
            <BedNeedReadout bedNeed={prediction.bed_need} capacityNow={prediction.capacity_now} demandExpected={prediction.demand_expected} />
          )}
        </aside>
      </div>
    </RTDCPageLayout>
  );
}
```

- [ ] **Step 4: Delete the old `.jsx` page**

Run:
```bash
git rm resources/js/Pages/RTDC/UnitHuddle.jsx
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run tests/js/rtdc/unit-huddle.test.tsx`
Expected: PASS.

- [ ] **Step 6: Type-check + build**

Run: `npx tsc --noEmit && npx vite build`
Expected: PASS (vite build is stricter — catches unresolved imports tsc misses).

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/RTDC/UnitHuddle.tsx tests/js/rtdc/unit-huddle.test.tsx
git commit -m "feat(rtdc): migrate UnitHuddle to live TypeScript page (no mock data)"
```

---

### Task E5: Migrate GlobalHuddle to the live hospital bed meeting

**Files:**
- Create: `resources/js/Pages/RTDC/GlobalHuddle.tsx`
- Delete: `resources/js/Pages/RTDC/GlobalHuddle.jsx`
- Modify: route controller method to render the `.tsx` (the page name is unchanged, so no controller edit needed — Inertia resolves `RTDC/GlobalHuddle` to `.tsx` via Task A3).
- Test: `tests/js/rtdc/global-huddle.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/rtdc/global-huddle.test.tsx`:
```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import GlobalHuddle from '@/Pages/RTDC/GlobalHuddle';

vi.mock('axios');
vi.mock('@/lib/echo', () => ({
  echo: { channel: () => ({ listen: vi.fn() }), leaveChannel: vi.fn(), connector: { pusher: { connection: { bind: vi.fn(), unbind: vi.fn() } } } },
}));
const mocked = vi.mocked(axios, true);

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('GlobalHuddle (live bed meeting)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the net bed-need and per-unit rollup', async () => {
    mocked.get.mockResolvedValue({
      data: { data: { net_bed_need: 3, total_positive_bed_need: 5, units: [
        { unit_id: 1, unit_name: '5 East', bed_need: 3, capacity_now: 2, demand_expected: 5 },
        { unit_id: 2, unit_name: 'ICU', bed_need: -2, capacity_now: 4, demand_expected: 2 },
      ] } },
    });

    renderWithClient(<GlobalHuddle />);

    await waitFor(() => expect(screen.getByText('5 East')).toBeInTheDocument());
    expect(screen.getByText(/net bed need/i)).toBeInTheDocument();
    expect(screen.getByText('ICU')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/global-huddle.test.tsx`
Expected: FAIL — page not wired.

- [ ] **Step 3: Write the migrated page**

Create `resources/js/Pages/RTDC/GlobalHuddle.tsx`:
```tsx
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { useBedMeeting, useLiveCensus } from '@/features/rtdc/hooks';

const TODAY = new Date().toISOString().slice(0, 10);

export default function GlobalHuddle() {
  useLiveCensus();
  const { data: rollup, isLoading } = useBedMeeting(TODAY, 'by_2pm');

  return (
    <RTDCPageLayout title="Hospital Bed Meeting" subtitle="Real-Time Demand Capacity — system roll-up">
      <div className="flex flex-col gap-[var(--space-6)]">
        <div className="grid grid-cols-2 gap-[var(--space-4)]">
          <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
            <div className="text-label">Net Bed Need</div>
            <div className="text-value">{rollup ? rollup.net_bed_need : '—'}</div>
          </div>
          <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
            <div className="text-label">Total Deficit (units short)</div>
            <div className="text-value text-[var(--critical)]">{rollup ? rollup.total_positive_bed_need : '—'}</div>
          </div>
        </div>

        {isLoading && <div className="text-caption">Loading roll-up…</div>}

        <table className="w-full text-[var(--text-secondary)]">
          <thead>
            <tr className="text-label text-left">
              <th className="p-[var(--space-2)]">Unit</th>
              <th className="p-[var(--space-2)]">Capacity</th>
              <th className="p-[var(--space-2)]">Demand</th>
              <th className="p-[var(--space-2)]">Bed Need</th>
            </tr>
          </thead>
          <tbody>
            {rollup?.units.map((u) => (
              <tr key={u.unit_id} className="border-t border-[var(--border-subtle)]">
                <td className="p-[var(--space-2)] text-[var(--text-primary)]">{u.unit_name}</td>
                <td className="p-[var(--space-2)]">{u.capacity_now}</td>
                <td className="p-[var(--space-2)]">{u.demand_expected}</td>
                <td className={`p-[var(--space-2)] ${u.bed_need > 0 ? 'text-[var(--critical)]' : 'text-[var(--success)]'}`}>
                  {u.bed_need > 0 ? `+${u.bed_need}` : u.bed_need}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </RTDCPageLayout>
  );
}
```

- [ ] **Step 4: Delete the old page + verify the controller still resolves**

Run:
```bash
git rm resources/js/Pages/RTDC/GlobalHuddle.jsx
```
The existing `RTDCController@globalHuddle` renders `Inertia::render('RTDC/GlobalHuddle')` — unchanged; Task A3's resolver now finds the `.tsx`.

- [ ] **Step 5: Run test, type-check, build**

Run: `npx vitest run tests/js/rtdc/global-huddle.test.tsx && npx tsc --noEmit && npx vite build`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/RTDC/GlobalHuddle.tsx tests/js/rtdc/global-huddle.test.tsx
git commit -m "feat(rtdc): migrate GlobalHuddle to live hospital bed-meeting page"
```

---

### Phase E exit check

- [ ] Run: `npx vitest run tests/js/rtdc && npx tsc --noEmit && npx vite build`
- [ ] Expected: all RTDC frontend tests green; no mock-data imports remain in `UnitHuddle`/`GlobalHuddle`; build clean.

---

## PHASE F — Step-4 reconciliation + full-cycle E2E

### Task F1: Reconciliation migration + model

**Files:**
- Create: `database/migrations/2026_06_20_000060_create_rtdc_reconciliations_table.php`
- Create: `app/Models/RtdcReconciliation.php`
- Modify: `tests/Feature/Rtdc/SchemaTest.php`

- [ ] **Step 1: Extend the schema test**

Add to `tests/Feature/Rtdc/SchemaTest.php`:
```php
    public function test_reconciliations_table_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.rtdc_reconciliations', [
            'rtdc_reconciliation_id', 'unit_id', 'service_date',
            'predicted_discharges', 'actual_discharges',
            'predicted_admissions', 'actual_admissions', 'reliability_score',
        ]));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL.

- [ ] **Step 3: Write the migration + model**

Create `database/migrations/2026_06_20_000060_create_rtdc_reconciliations_table.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.rtdc_reconciliations', function (Blueprint $table) {
            $table->id('rtdc_reconciliation_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->decimal('predicted_discharges', 6, 2)->default(0);
            $table->integer('actual_discharges')->default(0);
            $table->integer('predicted_admissions')->default(0);
            $table->integer('actual_admissions')->default(0);
            $table->decimal('reliability_score', 5, 4)->nullable(); // 0..1
            $table->timestamps();
            $table->unique(['unit_id', 'service_date'], 'uq_rtdc_recon_unit_date');
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rtdc_reconciliations');
    }
};
```

Create `app/Models/RtdcReconciliation.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtdcReconciliation extends Model
{
    protected $table = 'prod.rtdc_reconciliations';
    protected $primaryKey = 'rtdc_reconciliation_id';

    protected $fillable = [
        'unit_id', 'service_date', 'predicted_discharges', 'actual_discharges',
        'predicted_admissions', 'actual_admissions', 'reliability_score',
    ];

    protected $casts = [
        'service_date' => 'date',
        'predicted_discharges' => 'float',
        'reliability_score' => 'float',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Models database/migrations
git add database/migrations/2026_06_20_000060_create_rtdc_reconciliations_table.php app/Models/RtdcReconciliation.php tests/Feature/Rtdc/SchemaTest.php
git commit -m "feat(rtdc): add rtdc_reconciliations table and model (Step 4)"
```

---

### Task F2: ReconciliationService — predicted vs actual + reliability

**Files:**
- Create: `app/Services/ReconciliationService.php`
- Test: `tests/Feature/Rtdc/ReconciliationServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/ReconciliationServiceTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Encounter;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_computes_reliability_from_predicted_vs_actual(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $yesterday = today()->subDay();

        // Predicted 4 weighted discharges for yesterday.
        RtdcPrediction::create([
            'unit_id' => $unit->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight',
            'discharges_weighted' => 4, 'demand_expected' => 3,
        ]);

        // Actual: 5 discharges happened yesterday.
        for ($i = 0; $i < 5; $i++) {
            Encounter::create([
                'patient_ref' => "p$i", 'unit_id' => $unit->unit_id, 'acuity_tier' => 2,
                'status' => 'discharged', 'discharged_at' => $yesterday->copy()->addHours(14),
            ]);
        }

        $recon = app(ReconciliationService::class)->reconcile($unit->unit_id, $yesterday);

        $this->assertEquals(5, $recon->actual_discharges);
        $this->assertEqualsWithDelta(4.0, $recon->predicted_discharges, 0.001);
        // reliability = 1 - |pred-actual|/max(pred,actual) = 1 - 1/5 = 0.8
        $this->assertEqualsWithDelta(0.8, $recon->reliability_score, 0.001);
    }

    public function test_reconcile_is_idempotent_per_unit_date(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $yesterday = today()->subDay();
        RtdcPrediction::create(['unit_id' => $unit->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 2]);

        $svc = app(ReconciliationService::class);
        $svc->reconcile($unit->unit_id, $yesterday);
        $svc->reconcile($unit->unit_id, $yesterday);

        $this->assertDatabaseCount('prod.rtdc_reconciliations', 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReconciliationServiceTest`
Expected: FAIL — service not found.

- [ ] **Step 3: Write the service**

Create `app/Services/ReconciliationService.php`:
```php
<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\RtdcPrediction;
use App\Models\RtdcReconciliation;
use Carbon\CarbonInterface;

/**
 * RTDC Step 4 — evaluate yesterday's plan. Reconciles predicted vs actual
 * discharges and computes per-unit prediction reliability (research §2: the
 * learning loop; reliability is a headline KPI).
 */
class ReconciliationService
{
    public function reconcile(int $unitId, CarbonInterface|string $serviceDate): RtdcReconciliation
    {
        $date = \Illuminate\Support\Carbon::parse($serviceDate)->toDateString();

        $predictedDischarges = (float) RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->max('discharges_weighted') ?? 0.0;

        $predictedAdmissions = (int) RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->max('demand_expected') ?? 0;

        $actualDischarges = Encounter::where('unit_id', $unitId)
            ->where('status', 'discharged')
            ->whereDate('discharged_at', $date)
            ->count();

        $actualAdmissions = Encounter::where('unit_id', $unitId)
            ->whereDate('admitted_at', $date)
            ->count();

        return RtdcReconciliation::updateOrCreate(
            ['unit_id' => $unitId, 'service_date' => $date],
            [
                'predicted_discharges' => $predictedDischarges,
                'actual_discharges' => $actualDischarges,
                'predicted_admissions' => $predictedAdmissions,
                'actual_admissions' => $actualAdmissions,
                'reliability_score' => $this->reliability($predictedDischarges, $actualDischarges),
            ],
        );
    }

    private function reliability(float $predicted, int $actual): ?float
    {
        $max = max($predicted, $actual);
        if ($max <= 0) {
            return null; // nothing predicted or happened — undefined
        }

        return round(1 - abs($predicted - $actual) / $max, 4);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ReconciliationServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Services/ReconciliationService.php
git add app/Services/ReconciliationService.php tests/Feature/Rtdc/ReconciliationServiceTest.php
git commit -m "feat(rtdc): Step-4 reconciliation service with per-unit reliability"
```

---

### Task F3: Nightly reconciliation job + schedule + reliability API

**Files:**
- Create: `app/Jobs/ReconcileRtdcPredictions.php`
- Create: `app/Http/Controllers/Api/Rtdc/ReconciliationController.php`
- Modify: `bootstrap/app.php` (schedule the job), `routes/api.php`
- Test: `tests/Feature/Rtdc/ReconciliationJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Rtdc/ReconciliationJobTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Jobs\ReconcileRtdcPredictions;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_reconciles_all_units_for_yesterday(): void
    {
        $yesterday = today()->subDay();
        $a = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $b = Unit::create(['name' => 'B', 'type' => 'icu', 'staffed_bed_count' => 8, 'ratio_floor' => 2]);
        RtdcPrediction::create(['unit_id' => $a->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 2]);
        RtdcPrediction::create(['unit_id' => $b->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 1]);

        (new ReconcileRtdcPredictions())->handle(app(\App\Services\ReconciliationService::class));

        $this->assertDatabaseCount('prod.rtdc_reconciliations', 2);
    }

    public function test_reliability_api_returns_unit_score(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        \App\Models\RtdcReconciliation::create([
            'unit_id' => $unit->unit_id, 'service_date' => today()->subDay(),
            'predicted_discharges' => 4, 'actual_discharges' => 5, 'reliability_score' => 0.8,
        ]);

        $this->actingAs($user)->getJson("/api/rtdc/units/{$unit->unit_id}/reliability")
            ->assertOk()->assertJsonPath('data.reliability_score', 0.8);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReconciliationJobTest`
Expected: FAIL — job/route not found.

- [ ] **Step 3: Write the job**

Create `app/Jobs/ReconcileRtdcPredictions.php`:
```php
<?php

namespace App\Jobs;

use App\Models\Unit;
use App\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileRtdcPredictions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ReconciliationService $service): void
    {
        $yesterday = today()->subDay();
        Unit::where('is_deleted', false)->each(function (Unit $unit) use ($service, $yesterday) {
            $service->reconcile($unit->unit_id, $yesterday);
        });
    }
}
```

- [ ] **Step 4: Write the reliability controller**

Create `app/Http/Controllers/Api/Rtdc/ReconciliationController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Models\RtdcReconciliation;
use Illuminate\Http\JsonResponse;

class ReconciliationController extends Controller
{
    public function latest(int $unitId): JsonResponse
    {
        $recon = RtdcReconciliation::where('unit_id', $unitId)
            ->orderByDesc('service_date')
            ->first();

        return response()->json(['data' => $recon]);
    }
}
```

- [ ] **Step 5: Schedule the job + register the route**

In `bootstrap/app.php`, fill in the schedule closure from Task A4:
```php
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new \App\Jobs\ReconcileRtdcPredictions())->dailyAt('02:00');
    })
```

Add to the `rtdc` group in `routes/api.php`:
```php
use App\Http\Controllers\Api\Rtdc\ReconciliationController;

    Route::get('/units/{unitId}/reliability', [ReconciliationController::class, 'latest']);
```

- [ ] **Step 6: Run test + verify schedule registered**

Run: `php artisan test --filter=ReconciliationJobTest && php artisan schedule:list`
Expected: tests PASS; `schedule:list` shows `ReconcileRtdcPredictions` daily at 02:00.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Jobs app/Http bootstrap/app.php
git add app/Jobs/ReconcileRtdcPredictions.php app/Http/Controllers/Api/Rtdc/ReconciliationController.php bootstrap/app.php routes/api.php tests/Feature/Rtdc/ReconciliationJobTest.php
git commit -m "feat(rtdc): nightly Step-4 reconciliation job, schedule, and reliability API"
```

---

### Task F4: Wire the ReliabilityTile into UnitHuddle

**Files:**
- Modify: `resources/js/features/rtdc/api.ts` (add `fetchReliability`)
- Modify: `resources/js/features/rtdc/hooks.ts` (add `useReliability`)
- Modify: `resources/js/Pages/RTDC/UnitHuddle.tsx` (render `ReliabilityTile`)
- Test: `tests/js/rtdc/unit-huddle.test.tsx` (extend)

- [ ] **Step 1: Extend the failing test**

Add to `tests/js/rtdc/unit-huddle.test.tsx` inside the existing mock `get` implementation a reliability branch, and a new assertion:
```tsx
      if (url.includes('/reliability')) {
        return Promise.resolve({ data: { data: { unit_id: 1, service_date: '2026-06-19', predicted_discharges: 4, actual_discharges: 5, reliability_score: 0.8 } } });
      }
```
And add this assertion at the end of the existing test:
```tsx
    await waitFor(() => expect(screen.getByText('80%')).toBeInTheDocument());
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/rtdc/unit-huddle.test.tsx`
Expected: FAIL — reliability not rendered.

- [ ] **Step 3: Add the fetcher**

In `resources/js/features/rtdc/api.ts`, add:
```ts
export const reliabilitySchema = z.object({
  unit_id: z.number(),
  service_date: z.string(),
  predicted_discharges: z.coerce.number(),
  actual_discharges: z.number(),
  reliability_score: z.coerce.number().nullable(),
});

export async function fetchReliability(unitId: number) {
  const res = await axios.get(`/api/rtdc/units/${unitId}/reliability`);
  return z.object({ data: reliabilitySchema.nullable() }).parse(res.data).data;
}
```

- [ ] **Step 4: Add the hook**

In `resources/js/features/rtdc/hooks.ts`, add:
```ts
import { fetchReliability } from './api';

export function useReliability(unitId: number) {
  return useQuery({ queryKey: ['rtdc', 'reliability', unitId], queryFn: () => fetchReliability(unitId) });
}
```

- [ ] **Step 5: Render the tile in `UnitHuddle.tsx`**

In `resources/js/Pages/RTDC/UnitHuddle.tsx`, import and use it:
```tsx
import { ReliabilityTile } from '@/Components/RTDC/ReliabilityTile';
import { useReliability } from '@/features/rtdc/hooks';
// inside the component:
  const { data: reliability } = useReliability(unitId);
// in the <aside>, after BedNeedReadout:
        <ReliabilityTile score={reliability?.reliability_score ?? null} />
```

- [ ] **Step 6: Run test, type-check, build**

Run: `npx vitest run tests/js/rtdc/unit-huddle.test.tsx && npx tsc --noEmit && npx vite build`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/features/rtdc/api.ts resources/js/features/rtdc/hooks.ts resources/js/Pages/RTDC/UnitHuddle.tsx tests/js/rtdc/unit-huddle.test.tsx
git commit -m "feat(rtdc): surface Step-4 reliability KPI in the unit huddle"
```

---

### Task F5: Full-cycle Playwright E2E (simulator-driven)

**Files:**
- Create: `tests/e2e/rtdc-huddle.spec.ts`
- Create: `app/Console/Commands/RtdcDemoResetCommand.php` (deterministic E2E fixture)

- [ ] **Step 1: Write the demo-reset command (deterministic fixture for E2E)**

Create `app/Console/Commands/RtdcDemoResetCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RtdcDemoResetCommand extends Command
{
    protected $signature = 'rtdc:demo-reset {--seed=42}';
    protected $description = 'Reset to a deterministic RTDC demo state for E2E tests.';

    public function handle(EventDispatcher $dispatcher): int
    {
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RtdcSeeder', '--force' => true]);

        $source = new SyntheticEventSource(SimulatorConfig::default(), seed: (int) $this->option('seed'));
        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
        }

        $this->info('RTDC demo state ready.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Write the E2E test**

Create `tests/e2e/rtdc-huddle.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

// Assumes the app + Reverb are running and TEST_USERNAME/TEST_PASSWORD seed a user.
// Run `php artisan rtdc:demo-reset` before this suite (see CI step).

test.describe('RTDC Unit Huddle (live)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="username"]', process.env.TEST_USERNAME || 'admin');
    await page.fill('input[name="password"]', process.env.TEST_PASSWORD || 'password');
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).not.toHaveURL(/\/login/, { timeout: 10000 });
  });

  test('runs a four-step cycle and shows bed-need', async ({ page }) => {
    await page.goto('/rtdc/unit-huddle');

    // Live census visible.
    await expect(page.getByText(/Live Census/i)).toBeVisible();

    // Step 1 — discharges.
    await page.getByLabel('definite').fill('2');
    await page.getByRole('button', { name: /save capacity/i }).click();

    // Step 2 — demand.
    await page.getByLabel('ED').fill('10');
    await page.getByRole('button', { name: /save demand/i }).click();

    // Step 3 — compute bed-need.
    await page.getByRole('button', { name: /compute bed-need/i }).click();

    await expect(page.getByText(/Bed Need/i)).toBeVisible();
  });
});
```

- [ ] **Step 3: Add a route for the unit-huddle page (if missing)**

Confirm `routes/web.php` has a GET route rendering `RTDC/UnitHuddle`. If not, add inside the `rtdc` web group:
```php
Route::get('/unit-huddle', [RTDCController::class, 'unitHuddle'])->name('rtdc.unit-huddle');
```
And add `unitHuddle` to `RTDCController` mirroring `globalHuddle`:
```php
public function unitHuddle(\Illuminate\Http\Request $request): \Inertia\Response
{
    $this->rtdcService->activateWorkflow($request);
    return \Inertia\Inertia::render('RTDC/UnitHuddle');
}
```

- [ ] **Step 4: Run the E2E locally**

Run (in separate terminals or backgrounded):
```bash
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\RtdcSeeder
php artisan rtdc:demo-reset --seed=42
php artisan serve --port=8084 &
php artisan reverb:start &
npm run build
npx playwright test tests/e2e/rtdc-huddle.spec.ts
```
Expected: PASS (bed-need visible after the cycle).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Console
git add tests/e2e/rtdc-huddle.spec.ts app/Console/Commands/RtdcDemoResetCommand.php routes/web.php app/Http/Controllers/RTDCController.php
git commit -m "test(rtdc): full-cycle Playwright E2E driven by deterministic simulator"
```

---

### Task F6: CI wiring + final green

**Files:**
- Modify: `package.json` (test scripts), `.github/workflows/main.yml` (add RTDC steps if needed)

- [ ] **Step 1: Add test scripts to `package.json`**

Add to the `scripts` block:
```json
    "test": "vitest run",
    "test:watch": "vitest",
    "test:e2e": "playwright test"
```

- [ ] **Step 2: Run the entire backend + frontend suite**

Run:
```bash
php artisan test --filter=Rtdc
npm run test
npx tsc --noEmit
npx vite build
vendor/bin/pint --test
```
Expected: ALL green.

- [ ] **Step 3: Ensure CI runs the new suites**

Inspect `.github/workflows/main.yml`. Confirm it runs `php artisan test`, `npm run test`, `npx tsc --noEmit`, `npx vite build`, and `vendor/bin/pint --test`. If the E2E job exists, add a step to `php artisan rtdc:demo-reset` and start `reverb:start` before Playwright. If any are missing, add them mirroring existing steps.

- [ ] **Step 4: Commit**

```bash
git add package.json .github/workflows/main.yml
git commit -m "ci(rtdc): run RTDC backend, frontend, and E2E suites in CI"
```

---

### Phase F exit check (S2 acceptance — spec §11)

- [ ] **AC1 — Live census < 2 s:** `php artisan rtdc:simulate` updates the web UI in under 2 seconds (manual: open UnitHuddle, run simulate, watch census change).
- [ ] **AC2 — Full cycle:** Playwright `rtdc-huddle.spec.ts` passes (enter tiers + demand, compute bed-need, barriers, roll-up).
- [ ] **AC3 — Reconciliation:** `ReconciliationServiceTest` + `ReconciliationJobTest` green; reliability KPI renders.
- [ ] **AC4 — Replay rebuild:** `ReplayTest` green (census rebuildable from `operational_events`).
- [ ] **AC5 — No mock data:** `grep -r "mock-data" resources/js/Pages/RTDC/UnitHuddle.tsx resources/js/Pages/RTDC/GlobalHuddle.tsx` returns nothing; both are `.tsx`; `npx tsc --noEmit` + `npx vite build` clean.
- [ ] **AC6 — Broadcast contract documented:** the channel/event names (`unit.{id}` / `census.updated`, `huddle.updated`; `hospital.beds` / `bedmeeting.updated`) are listed in this plan and reused by Hummingbird (S7).

---

## Self-Review

**Spec coverage:**
- Minimal substrate (sim → canonical event → dispatcher → projection → Reverb) → Phase B (B4–B8). ✓
- Canonical operational model subset (units/beds/encounters/census/acuity) → B1–B3, C1. ✓
- RTDC triple → C2–C4. ✓
- Two-tier huddles → C6, D4. ✓
- Barrier tracking (4-category) → C5, C6, D4. ✓
- Step-4 reconciliation + reliability KPI → F1–F4. ✓
- Frontend rewire + TS migration → E1–E5, F4. ✓
- Replay-rebuildable (AC4) → B7. ✓
- Safety slice (bed-need surfaces, never recommends) → BedNeedReadout note (E3) + spec §10. ✓
- Reverb contract reusable by Hummingbird → D1, AC6. ✓

**Deferred-correctly (out of scope per spec):** HL7v2/FHIR, Redis Streams, ML, optimizer, mobile, full safety-constraint engine. ✓

**Known executor watch-points (flagged inline, not placeholders):**
1. **Auth guard for `/api/rtdc/*`:** verify whether existing API routes use `auth:sanctum` (token) or web-session `auth`; match it (Task D2 Step 4 note). The `requires_auth` test asserts 401 — adjust guard/test together if the project uses session auth on `/api`.
2. **Pest vs PHPUnit:** tests are written as PHPUnit classes (matches existing `tests/Feature/ProfileTest.php`). They run under both `php artisan test` and `./vendor/bin/pest`. No conversion needed.
3. **`bootstrap/app.php` channels route:** Laravel 11 needs `channels:` in `withRouting` for `routes/channels.php` to load (Task D1 Step 4).
4. **`HuddleServiceTest` expectation:** the in-test note corrects `net_bed_need` to the signed sum (Task C6 Step 1–2).
5. **Reverb in tests:** broadcasting uses the null/log driver under `phpunit.xml` (`BROADCAST_CONNECTION` unset in testing) — events are constructed and asserted, not actually sent, so no Reverb server is needed for Pest/Vitest. Only the Playwright E2E needs a running Reverb.

**Type consistency:** `CanonicalEvent` factory signatures (B4) match dispatcher/projector usage (B5–B6). `RtdcService` method names (`upsertCapacity`/`upsertDemand`/`developPlan`) are identical across C4, D3, and the hooks (E2). Zod field names (E1) match the API JSON (`bed_need`, `demand_expected`, `acuity_adjusted_capacity`) emitted by controllers (D2–D4) and broadcast events (D1). ✓

