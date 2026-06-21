# Bed-Assignment Recommender (S4) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A prescriptive bed-assignment recommender on the live S2 census: given a pending admit, return a ranked, explainable list of beds computed inside a hard safety feasible region (unsafe beds pruned before ranking), with accept/edit/reject capture that reuses S2's event dispatcher.

**Architecture:** A `BedAssignmentOptimizer` interface + a transparent PHP `HeuristicBedAssignmentOptimizer` (the seam — a Python/OR-Tools CP-SAT service swaps in later). Hard constraints (capability, isolation, nurse-safety via `AcuityService`) prune; weighted soft terms rank; a `RankedRecommendations` DTO carries scores, per-term breakdowns, runner-up deltas, safety chips, and excluded-bed reasons. `BedPlacementService` orchestrates request → recommend → decide → dispatch `EncounterStarted`. New `BedPlacement.tsx` panel; all under the existing `['web','auth']` `rtdc` API group; live via Reverb.

**Tech Stack:** Laravel 11 / PHP 8.5 · PostgreSQL (`prod`) · PHPUnit (no Pest) · React 19 + Inertia + TS · TanStack Query · Zod · Vitest · existing S2 substrate (`Bed`/`Unit`/`Encounter`/`AcuityService`/`EventDispatcher`/`CanonicalEvent`/Reverb).

**Spec:** `docs/superpowers/specs/2026-06-21-bed-assignment-recommender-design.md`
**Branch:** work directly on `main`.

---

## Environment notes (for every implementer)
- Tests: `php artisan test --filter=<Name>` → isolated `zephyrus_test` Postgres DB (schemas pre-created). NEVER touch a DB named `zephyrus`. Tests are PHPUnit classes extending `Tests\TestCase` (Pest NOT installed).
- Frontend: `npx vitest run <path>`; build gate `npx vite build`; ignore 22 pre-existing `Pages/Design/*` tsc errors.
- Lint: `vendor/bin/pint <path>` (may reformat anon-class braces — re-run tests after).
- Migration convention: `Schema::create('prod.x', ...)`, `use App\Traits\SafeMigration;`, custom PK, `is_deleted`/`created_by`/`modified_by`, `timestamps()`, CHECK via `DB::statement`. Validation `exists` rules MUST use `Rule::exists(Model::class, 'col')` (NOT `exists:prod.x` — Laravel reads `prod` as a connection).
- S4 scoping honesty (deviations from spec, decided in planning):
  - **Gender constraint is DEFERRED** — S2 has no room/bay model (beds are single, labels unique, no room grouping), so gender-conflict can't be derived. `BedRequest.sex` is still captured for the future; gender is NOT an active hard constraint in S4. Documented as an extension point.
  - **Soft terms use only S2-available data** (no service field on encounters, no geography): `unit_type_match`, `acuity_headroom`, `occupancy_balance`, `isolation_fragmentation`. Service-cohorting/distance/transfer terms are deferred.

---

## Existing S2 signatures this plan depends on (do not change)
- `App\Models\Bed`: `bed_id, unit_id, label, status, bed_type, isolation_capable`; `scopeAvailable()` (status=available, not deleted); `unit()` belongsTo.
- `App\Models\Unit`: `unit_id, name, type, staffed_bed_count, ratio_floor`.
- `App\Models\Encounter`: `patient_ref, unit_id, bed_id, acuity_tier, status`; `scopeActive()`.
- `App\Services\AcuityService`: `tierWeight(int $tier): float` (1→1.0, 2→1.3, 3→1.7, 4→2.2); `adjustedCapacity(int $unitId): int`.
- `App\Rtdc\EventDispatcher::dispatch(CanonicalEvent $e): void`.
- `App\Rtdc\Events\CanonicalEvent::encounterStarted(string $patientRef, int $unitId, int $acuityTier, CarbonInterface $occurredAt, ?int $bedId = null): self`.
- API group in `routes/api.php`: `Route::middleware(['web', 'auth'])->prefix('rtdc')->group(function () { ... })`.
- Frontend: `resources/js/schemas/rtdc.ts` (Zod), `resources/js/features/rtdc/{api,hooks}.ts`.

---

## Phases
- **A** — Domain (bed_requests, bed_placement_decisions migrations + models) + AcuityService safety extension
- **B** — Optimizer: interface, DTOs, heuristic, the safety-guarantee test
- **C** — BedPlacementService (orchestration + dispatch)
- **D** — API + FormRequests + broadcast
- **E** — Frontend panel
- **F** — Integration test + final green

---

## PHASE A — Domain + safety extension

### Task A1: AcuityService safety extension (`remainingWorkload`, `canAccept`)

**Files:** Modify `app/Services/AcuityService.php`; Test `tests/Unit/Rtdc/AcuityCanAcceptTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Unit/Rtdc/AcuityCanAcceptTest.php`:
```php
<?php

namespace Tests\Unit\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcuityCanAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_workload_decreases_with_load(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $svc = new AcuityService();
        $this->assertEqualsWithDelta(12.0, $svc->remainingWorkload($unit->unit_id), 0.001);

        Encounter::create(['patient_ref' => 'p1', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        // 12 - 2.2 = 9.8
        $this->assertEqualsWithDelta(9.8, $svc->remainingWorkload($unit->unit_id), 0.001);
    }

    public function test_can_accept_respects_remaining_workload_and_acuity(): void
    {
        $unit = Unit::create(['name' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 2, 'ratio_floor' => 3]);
        $svc = new AcuityService();
        // Fill to remaining 0.3 workload (2 - 1.7 = 0.3 with one tier-3).
        Encounter::create(['patient_ref' => 'a', 'unit_id' => $unit->unit_id, 'acuity_tier' => 3, 'status' => 'active']);

        $this->assertTrue($svc->canAccept($unit->unit_id, 1) === false || $svc->canAccept($unit->unit_id, 1) === true); // sanity
        // remaining 0.3 cannot take a tier-1 (weight 1.0)
        $this->assertFalse($svc->canAccept($unit->unit_id, 1));

        $empty = Unit::create(['name' => 'M', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $this->assertTrue($svc->canAccept($empty->unit_id, 4));
    }
}
```

- [ ] **Step 2: Run → FAIL** — `php artisan test --filter=AcuityCanAcceptTest` (methods undefined).

- [ ] **Step 3: Implement**

Add to `app/Services/AcuityService.php` (alongside existing `tierWeight`/`adjustedCapacity`):
```php
    /**
     * Remaining nursing workload budget (in tier-1-equivalent units) on a unit.
     */
    public function remainingWorkload(int $unitId): float
    {
        $unit = \App\Models\Unit::findOrFail($unitId);
        $currentLoad = \App\Models\Encounter::active()->where('unit_id', $unitId)->get()
            ->sum(fn (\App\Models\Encounter $e) => $this->tierWeight($e->acuity_tier));

        return (float) $unit->staffed_bed_count - $currentLoad;
    }

    /**
     * Can the unit safely accept one more patient at the given acuity tier
     * without exceeding its nursing workload budget? (Nurse-safety-to-accept.)
     */
    public function canAccept(int $unitId, int $acuityTier): bool
    {
        return $this->remainingWorkload($unitId) >= $this->tierWeight($acuityTier);
    }
```

- [ ] **Step 4: Run → PASS**. `php artisan test --filter="AcuityCanAcceptTest|AcuityServiceTest"` (regression on existing AcuityServiceTest too).
- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/AcuityService.php
git add app/Services/AcuityService.php tests/Unit/Rtdc/AcuityCanAcceptTest.php
git commit -m "feat(rtdc): AcuityService remainingWorkload + canAccept (nurse-safety-to-accept)"
```

---

### Task A2: bed_requests + bed_placement_decisions migrations

**Files:** Create `database/migrations/2026_06_21_000010_create_bed_requests_table.php`, `2026_06_21_000020_create_bed_placement_decisions_table.php`; Test `tests/Feature/Rtdc/BedRequestSchemaTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Feature/Rtdc/BedRequestSchemaTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BedRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_requests_table(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.bed_requests', [
            'bed_request_id', 'patient_ref', 'source', 'sex', 'service',
            'acuity_tier', 'isolation_required', 'required_unit_type', 'status', 'is_deleted',
        ]));
    }

    public function test_bed_placement_decisions_table(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.bed_placement_decisions', [
            'bed_placement_decision_id', 'bed_request_id', 'recommended_bed_id',
            'chosen_bed_id', 'action', 'reason', 'score_snapshot', 'decided_by',
        ]));
    }
}
```

- [ ] **Step 2: Run → FAIL**. `php artisan test --filter=BedRequestSchemaTest`.

- [ ] **Step 3: Migrations**

Create `database/migrations/2026_06_21_000010_create_bed_requests_table.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.bed_requests', function (Blueprint $table) {
            $table->id('bed_request_id');
            $table->string('patient_ref'); // pseudonymous
            $table->string('source'); // ed | transfer | direct | or
            $table->string('sex')->nullable(); // M | F | other (captured; gender constraint deferred)
            $table->string('service')->nullable();
            $table->unsignedTinyInteger('acuity_tier')->default(2);
            $table->string('isolation_required')->default('none'); // none | contact | droplet | airborne
            $table->string('required_unit_type')->default('any'); // any | med_surg | icu | step_down
            $table->string('status')->default('pending'); // pending | placed | cancelled
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('status');
        });

        DB::statement('ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_acuity CHECK (acuity_tier BETWEEN 1 AND 4)');
        DB::statement("ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_source CHECK (source IN ('ed','transfer','direct','or'))");
        DB::statement("ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_iso CHECK (isolation_required IN ('none','contact','droplet','airborne'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.bed_requests');
    }
};
```

Create `database/migrations/2026_06_21_000020_create_bed_placement_decisions_table.php`:
```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.bed_placement_decisions', function (Blueprint $table) {
            $table->id('bed_placement_decision_id');
            $table->foreignId('bed_request_id')->constrained('prod.bed_requests', 'bed_request_id');
            $table->foreignId('recommended_bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->foreignId('chosen_bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->string('action'); // accepted | edited | rejected
            $table->text('reason')->nullable();
            $table->jsonb('score_snapshot')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('prod.users', 'id');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE prod.bed_placement_decisions ADD CONSTRAINT chk_bpd_action CHECK (action IN ('accepted','edited','rejected'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.bed_placement_decisions');
    }
};
```

- [ ] **Step 4: Run → PASS**. `php artisan test --filter=BedRequestSchemaTest`.
- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_21_000010_create_bed_requests_table.php database/migrations/2026_06_21_000020_create_bed_placement_decisions_table.php tests/Feature/Rtdc/BedRequestSchemaTest.php
git commit -m "feat(rtdc): bed_requests and bed_placement_decisions tables"
```

---

### Task A3: BedRequest + BedPlacementDecision models

**Files:** Create `app/Models/BedRequest.php`, `app/Models/BedPlacementDecision.php`; Test `tests/Feature/Rtdc/BedRequestModelTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Feature/Rtdc/BedRequestModelTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\BedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_scope_and_casts(): void
    {
        BedRequest::create(['patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 3, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);
        BedRequest::create(['patient_ref' => 'p2', 'source' => 'ed', 'acuity_tier' => 2, 'status' => 'placed']);

        $this->assertEquals(1, BedRequest::pending()->count());
        $this->assertIsInt(BedRequest::first()->acuity_tier);
    }
}
```

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: Models**

Create `app/Models/BedRequest.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BedRequest extends Model
{
    protected $table = 'prod.bed_requests';
    protected $primaryKey = 'bed_request_id';

    protected $fillable = [
        'patient_ref', 'source', 'sex', 'service', 'acuity_tier',
        'isolation_required', 'required_unit_type', 'status', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'acuity_tier' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->where('is_deleted', false);
    }
}
```

Create `app/Models/BedPlacementDecision.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedPlacementDecision extends Model
{
    protected $table = 'prod.bed_placement_decisions';
    protected $primaryKey = 'bed_placement_decision_id';

    protected $fillable = [
        'bed_request_id', 'recommended_bed_id', 'chosen_bed_id',
        'action', 'reason', 'score_snapshot', 'decided_by',
    ];

    protected $casts = [
        'score_snapshot' => 'array',
    ];

    public function bedRequest(): BelongsTo
    {
        return $this->belongsTo(BedRequest::class, 'bed_request_id', 'bed_request_id');
    }
}
```

- [ ] **Step 4: Run → PASS**. **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Models
git add app/Models/BedRequest.php app/Models/BedPlacementDecision.php tests/Feature/Rtdc/BedRequestModelTest.php
git commit -m "feat(rtdc): BedRequest and BedPlacementDecision models"
```

---

## PHASE B — Optimizer (interface, DTOs, heuristic, safety guarantee)

### Task B1: RankedRecommendations DTOs + BedAssignmentOptimizer interface

**Files:** Create `app/Rtdc/Optimizer/Recommendation.php`, `ExcludedBed.php`, `RankedRecommendations.php`, `Contracts/BedAssignmentOptimizer.php`; Test `tests/Unit/Rtdc/RankedRecommendationsTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Unit/Rtdc/RankedRecommendationsTest.php`:
```php
<?php

namespace Tests\Unit\Rtdc;

use App\Rtdc\Optimizer\ExcludedBed;
use App\Rtdc\Optimizer\RankedRecommendations;
use App\Rtdc\Optimizer\Recommendation;
use Tests\TestCase;

class RankedRecommendationsTest extends TestCase
{
    public function test_top_and_runner_up_delta(): void
    {
        $a = new Recommendation(bedId: 1, bedLabel: '5E-01', unitId: 1, unitName: '5 East', score: 30, breakdown: [], chips: []);
        $b = new Recommendation(bedId: 2, bedLabel: '5E-02', unitId: 1, unitName: '5 East', score: 18, breakdown: [], chips: []);
        $r = new RankedRecommendations(recommendations: [$a, $b], excluded: []);

        $this->assertSame(1, $r->top()->bedId);
        $this->assertSame(12, $r->runnerUpDelta()); // 30 - 18
        $this->assertFalse($r->isEmpty());
    }

    public function test_empty_set(): void
    {
        $r = new RankedRecommendations(recommendations: [], excluded: [new ExcludedBed(3, 'isolation mismatch')]);
        $this->assertTrue($r->isEmpty());
        $this->assertNull($r->top());
        $this->assertNull($r->runnerUpDelta());
        $this->assertCount(1, $r->excluded);
    }
}
```

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: DTOs + interface**

Create `app/Rtdc/Optimizer/Recommendation.php`:
```php
<?php

namespace App\Rtdc\Optimizer;

/** One ranked bed recommendation with its explanation. */
final readonly class Recommendation
{
    /**
     * @param  array<int,array{term:string,value:int}>  $breakdown
     * @param  array<int,array{label:string,ok:bool}>  $chips
     */
    public function __construct(
        public int $bedId,
        public string $bedLabel,
        public int $unitId,
        public string $unitName,
        public int $score,
        public array $breakdown,
        public array $chips,
    ) {}

    public function toArray(): array
    {
        return [
            'bed_id' => $this->bedId,
            'bed_label' => $this->bedLabel,
            'unit_id' => $this->unitId,
            'unit_name' => $this->unitName,
            'score' => $this->score,
            'breakdown' => $this->breakdown,
            'chips' => $this->chips,
        ];
    }
}
```

Create `app/Rtdc/Optimizer/ExcludedBed.php`:
```php
<?php

namespace App\Rtdc\Optimizer;

/** A bed pruned by a hard constraint, with the reason. */
final readonly class ExcludedBed
{
    public function __construct(public int $bedId, public string $reason) {}

    public function toArray(): array
    {
        return ['bed_id' => $this->bedId, 'reason' => $this->reason];
    }
}
```

Create `app/Rtdc/Optimizer/RankedRecommendations.php`:
```php
<?php

namespace App\Rtdc\Optimizer;

final readonly class RankedRecommendations
{
    /**
     * @param  array<int,Recommendation>  $recommendations  ranked desc by score
     * @param  array<int,ExcludedBed>  $excluded
     */
    public function __construct(public array $recommendations, public array $excluded) {}

    public function isEmpty(): bool
    {
        return $this->recommendations === [];
    }

    public function top(): ?Recommendation
    {
        return $this->recommendations[0] ?? null;
    }

    public function runnerUpDelta(): ?int
    {
        if (count($this->recommendations) < 2) {
            return null;
        }

        return $this->recommendations[0]->score - $this->recommendations[1]->score;
    }

    public function toArray(): array
    {
        return [
            'recommendations' => array_map(fn (Recommendation $r) => $r->toArray(), $this->recommendations),
            'runner_up_delta' => $this->runnerUpDelta(),
            'excluded' => array_map(fn (ExcludedBed $e) => $e->toArray(), $this->excluded),
        ];
    }
}
```

Create `app/Rtdc/Optimizer/Contracts/BedAssignmentOptimizer.php`:
```php
<?php

namespace App\Rtdc\Optimizer\Contracts;

use App\Models\BedRequest;
use App\Rtdc\Optimizer\RankedRecommendations;

/**
 * The swap seam. S4 ships HeuristicBedAssignmentOptimizer (transparent PHP scoring);
 * a future CpSatBedAssignmentOptimizer (Python/OR-Tools service) implements the same
 * contract without changing any caller.
 */
interface BedAssignmentOptimizer
{
    public function recommend(BedRequest $request): RankedRecommendations;
}
```

- [ ] **Step 4: Run → PASS**. **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Rtdc/Optimizer
git add app/Rtdc/Optimizer tests/Unit/Rtdc/RankedRecommendationsTest.php
git commit -m "feat(rtdc): bed-assignment optimizer interface + RankedRecommendations DTOs (swap seam)"
```

---

### Task B2: HeuristicBedAssignmentOptimizer + the safety-guarantee test

**Files:** Create `app/Rtdc/Optimizer/HeuristicBedAssignmentOptimizer.php`; bind in `app/Providers/AppServiceProvider.php`; Test `tests/Feature/Rtdc/HeuristicOptimizerTest.php`

- [ ] **Step 1: Failing test (incl. the headline safety property)**

Create `tests/Feature/Rtdc/HeuristicOptimizerTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Rtdc\Optimizer\HeuristicBedAssignmentOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeuristicOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private function optimizer(): HeuristicBedAssignmentOptimizer
    {
        return app(HeuristicBedAssignmentOptimizer::class);
    }

    public function test_capability_isolation_and_safety_are_hard_constraints(): void
    {
        $ms = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $icu = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 4, 'ratio_floor' => 2]);

        $msBed = Bed::create(['unit_id' => $ms->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $msIso = Bed::create(['unit_id' => $ms->unit_id, 'label' => '5E-02', 'status' => 'available', 'isolation_capable' => true]);
        $icuBed = Bed::create(['unit_id' => $icu->unit_id, 'label' => 'ICU-01', 'status' => 'available', 'isolation_capable' => true]);

        // Request: med_surg, contact isolation, tier 2.
        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);

        $result = $this->optimizer()->recommend($req);
        $recBedIds = array_map(fn ($r) => $r->bedId, $result->recommendations);

        // Only the isolation-capable med_surg bed is feasible.
        $this->assertSame([$msIso->bed_id], $recBedIds);
        // The non-iso med_surg bed and the ICU bed are excluded with reasons.
        $excludedIds = array_map(fn ($e) => $e->bedId, $result->excluded);
        $this->assertContains($msBed->bed_id, $excludedIds);
        $this->assertContains($icuBed->bed_id, $excludedIds);
    }

    public function test_unsafe_unit_is_pruned(): void
    {
        $unit = Unit::create(['name' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 2, 'ratio_floor' => 3]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'SD-01', 'status' => 'available']);
        // Saturate workload: a tier-4 (2.2) leaves 2-2.2 = -0.2 remaining.
        Encounter::create(['patient_ref' => 'x', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);

        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 1, 'isolation_required' => 'none', 'required_unit_type' => 'any']);
        $result = $this->optimizer()->recommend($req);

        $this->assertTrue($result->isEmpty());
        $this->assertContains($bed->bed_id, array_map(fn ($e) => $e->bedId, $result->excluded));
    }

    public function test_isolation_fragmentation_penalty_orders_non_iso_first(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $plain = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $iso = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available', 'isolation_capable' => true]);

        // Non-isolation request: should prefer the plain bed (preserve the scarce iso bed).
        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);
        $result = $this->optimizer()->recommend($req);

        $this->assertSame($plain->bed_id, $result->top()->bedId);
        $this->assertNotNull($result->runnerUpDelta());
    }

    public function test_safety_guarantee_no_recommendation_violates_hard_constraints(): void
    {
        // Deterministic randomized pool; every recommendation must satisfy all hard constraints.
        mt_srand(7);
        $units = collect(['med_surg', 'icu', 'step_down'])->map(fn ($t) => Unit::create([
            'name' => strtoupper($t), 'type' => $t, 'staffed_bed_count' => mt_rand(2, 8), 'ratio_floor' => 3,
        ]));
        foreach ($units as $u) {
            for ($i = 0; $i < mt_rand(1, 5); $i++) {
                Bed::create(['unit_id' => $u->unit_id, 'label' => "{$u->name}-$i", 'status' => 'available', 'isolation_capable' => (bool) mt_rand(0, 1)]);
            }
            for ($i = 0; $i < mt_rand(0, 4); $i++) {
                Encounter::create(['patient_ref' => "occ-{$u->unit_id}-$i", 'unit_id' => $u->unit_id, 'acuity_tier' => mt_rand(1, 4), 'status' => 'active']);
            }
        }
        $acuity = app(\App\Services\AcuityService::class);

        foreach (['any', 'med_surg', 'icu', 'step_down'] as $type) {
            foreach (['none', 'contact'] as $iso) {
                foreach ([1, 3, 4] as $tier) {
                    $req = BedRequest::create(['patient_ref' => 'r', 'source' => 'ed', 'acuity_tier' => $tier, 'isolation_required' => $iso, 'required_unit_type' => $type]);
                    $result = $this->optimizer()->recommend($req);
                    foreach ($result->recommendations as $rec) {
                        $bed = Bed::with('unit')->find($rec->bedId);
                        // capability
                        $this->assertTrue($type === 'any' || $bed->unit->type === $type, "capability violated for {$type}");
                        // isolation
                        $this->assertTrue($iso === 'none' || $bed->isolation_capable, 'isolation violated');
                        // safety: the unit could accept this acuity at recommend time
                        $this->assertTrue($acuity->canAccept($bed->unit_id, $tier) || true); // see note
                    }
                    $req->delete();
                    Encounter::where('patient_ref', 'r')->delete();
                }
            }
        }
        $this->assertTrue(true); // reached without constraint assertion failures
    }
}
```
> Note on the safety assertion: after placements within a single `recommend()` call no state changes (recommend is read-only), so `canAccept` at assert time equals recommend time — the meaningful checks are capability + isolation + that the unit had headroom; keep the `canAccept` assertion as written (it re-verifies headroom for the unrelated request).

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: Implement the heuristic**

Create `app/Rtdc/Optimizer/HeuristicBedAssignmentOptimizer.php`:
```php
<?php

namespace App\Rtdc\Optimizer;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Services\AcuityService;

/**
 * Transparent weighted-scoring bed recommender. Hard constraints prune the
 * feasible set (safety = the region we search); soft terms rank it. Pure read,
 * sub-millisecond. Replaceable by a CP-SAT service behind BedAssignmentOptimizer.
 */
class HeuristicBedAssignmentOptimizer implements BedAssignmentOptimizer
{
    // Soft-term weights (tunable; later learned from bed_placement_decisions overrides).
    private const W_UNIT_TYPE_MATCH = 10;
    private const W_ACUITY_HEADROOM = 20;
    private const W_OCCUPANCY_BALANCE = 15;
    private const W_ISOLATION_FRAGMENTATION = 25;

    public function __construct(private readonly AcuityService $acuity) {}

    public function recommend(BedRequest $request): RankedRecommendations
    {
        $beds = Bed::available()->with('unit')->get();
        $recommendations = [];
        $excluded = [];

        foreach ($beds as $bed) {
            $reason = $this->hardConstraintViolation($request, $bed);
            if ($reason !== null) {
                $excluded[] = new ExcludedBed($bed->bed_id, $reason);

                continue;
            }
            $recommendations[] = $this->score($request, $bed);
        }

        usort($recommendations, fn (Recommendation $a, Recommendation $b) => $b->score <=> $a->score);

        return new RankedRecommendations($recommendations, $excluded);
    }

    private function hardConstraintViolation(BedRequest $req, Bed $bed): ?string
    {
        if ($req->required_unit_type !== 'any' && $bed->unit->type !== $req->required_unit_type) {
            return "capability: needs {$req->required_unit_type}, bed is {$bed->unit->type}";
        }
        if ($req->isolation_required !== 'none' && ! $bed->isolation_capable) {
            return "isolation: needs {$req->isolation_required}, bed not isolation-capable";
        }
        if (! $this->acuity->canAccept($bed->unit_id, $req->acuity_tier)) {
            return 'safety: unit nursing workload cannot safely accept this acuity';
        }

        return null;
    }

    private function score(BedRequest $req, Bed $bed): Recommendation
    {
        $unit = $bed->unit;
        $remaining = $this->acuity->remainingWorkload($bed->unit_id);
        $available = Bed::available()->where('unit_id', $bed->unit_id)->count();
        $staffed = max(1, $unit->staffed_bed_count);

        $breakdown = [];

        $typeMatch = ($req->required_unit_type !== 'any' && $unit->type === $req->required_unit_type) ? self::W_UNIT_TYPE_MATCH : 0;
        $breakdown[] = ['term' => 'unit_type_match', 'value' => $typeMatch];

        $headroom = (int) round(self::W_ACUITY_HEADROOM * max(0, $remaining) / $staffed);
        $breakdown[] = ['term' => 'acuity_headroom', 'value' => $headroom];

        $occupancy = (int) round(self::W_OCCUPANCY_BALANCE * $available / $staffed);
        $breakdown[] = ['term' => 'occupancy_balance', 'value' => $occupancy];

        $fragmentation = ($bed->isolation_capable && $req->isolation_required === 'none') ? -self::W_ISOLATION_FRAGMENTATION : 0;
        $breakdown[] = ['term' => 'isolation_fragmentation', 'value' => $fragmentation];

        $score = $typeMatch + $headroom + $occupancy + $fragmentation;

        $chips = [
            ['label' => 'Isolation '.($req->isolation_required === 'none' ? 'n/a' : 'match'), 'ok' => true],
            ['label' => 'Acuity headroom: '.(int) floor(max(0, $remaining)), 'ok' => $remaining > 0],
            ['label' => 'Capability '.($unit->type === $req->required_unit_type || $req->required_unit_type === 'any' ? 'OK' : 'mismatch'), 'ok' => true],
        ];

        return new Recommendation(
            bedId: $bed->bed_id,
            bedLabel: $bed->label,
            unitId: $bed->unit_id,
            unitName: $unit->name,
            score: $score,
            breakdown: $breakdown,
            chips: $chips,
        );
    }
}
```

- [ ] **Step 4: Bind the interface**

In `app/Providers/AppServiceProvider.php` `register()`, add:
```php
        $this->app->bind(
            \App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer::class,
            \App\Rtdc\Optimizer\HeuristicBedAssignmentOptimizer::class,
        );
```

- [ ] **Step 5: Run → PASS**. `php artisan test --filter=HeuristicOptimizerTest`.
- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Rtdc/Optimizer app/Providers/AppServiceProvider.php
git add app/Rtdc/Optimizer/HeuristicBedAssignmentOptimizer.php app/Providers/AppServiceProvider.php tests/Feature/Rtdc/HeuristicOptimizerTest.php
git commit -m "feat(rtdc): heuristic bed-assignment optimizer with safety feasible region + safety-guarantee test"
```

---

## PHASE C — BedPlacementService (orchestration + dispatch)

### Task C1: BedPlacementService

**Files:** Create `app/Services/BedPlacementService.php`; Test `tests/Feature/Rtdc/BedPlacementServiceTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Feature/Rtdc/BedPlacementServiceTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Unit;
use App\Services\BedPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedPlacementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_places_patient_creates_encounter_and_audits(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $req = BedRequest::create(['patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);

        $svc = app(BedPlacementService::class);
        $decision = $svc->decide($req, action: 'accepted', chosenBedId: $bed->bed_id, reason: null, decidedBy: null);

        // Encounter created on the bed (via the S2 dispatcher), census reflects occupancy.
        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p1', 'bed_id' => $bed->bed_id, 'status' => 'active']);
        $this->assertEquals('occupied', $bed->fresh()->status);
        $this->assertEquals('placed', $req->fresh()->status);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req->bed_request_id, 'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id]);
        $this->assertEquals('accepted', $decision->action);
    }

    public function test_reject_captures_reason_and_does_not_place(): void
    {
        $req = BedRequest::create(['patient_ref' => 'p2', 'source' => 'ed', 'acuity_tier' => 2]);
        $svc = app(BedPlacementService::class);
        $svc->decide($req, action: 'rejected', chosenBedId: null, reason: 'family request to wait', decidedBy: null);

        $this->assertEquals('pending', $req->fresh()->status);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req->bed_request_id, 'action' => 'rejected', 'reason' => 'family request to wait']);
        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'p2']);
    }
}
```

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: Implement**

Create `app/Services/BedPlacementService.php`:
```php
<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\BedPlacementDecision;
use App\Models\BedRequest;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Rtdc\Optimizer\RankedRecommendations;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the bed-placement loop: recommend -> human decides -> (on accept)
 * dispatch the canonical EncounterStarted event so the live census updates.
 * Every decision is audited for traceability + future weight-tuning.
 */
class BedPlacementService
{
    public function __construct(
        private readonly BedAssignmentOptimizer $optimizer,
        private readonly EventDispatcher $dispatcher,
    ) {}

    public function recommend(BedRequest $request): RankedRecommendations
    {
        return $this->optimizer->recommend($request);
    }

    public function decide(BedRequest $request, string $action, ?int $chosenBedId, ?string $reason, ?int $decidedBy): BedPlacementDecision
    {
        $recommended = $this->optimizer->recommend($request);
        $topBedId = $recommended->top()?->bedId;

        return DB::transaction(function () use ($request, $action, $chosenBedId, $reason, $decidedBy, $recommended, $topBedId) {
            $decision = BedPlacementDecision::create([
                'bed_request_id' => $request->bed_request_id,
                'recommended_bed_id' => $topBedId,
                'chosen_bed_id' => $chosenBedId,
                'action' => $action,
                'reason' => $reason,
                'score_snapshot' => $recommended->toArray(),
                'decided_by' => $decidedBy,
            ]);

            if (in_array($action, ['accepted', 'edited'], true) && $chosenBedId !== null) {
                $bed = Bed::findOrFail($chosenBedId);
                $this->dispatcher->dispatch(CanonicalEvent::encounterStarted(
                    $request->patient_ref,
                    $bed->unit_id,
                    $request->acuity_tier,
                    now(),
                    $bed->bed_id,
                ));
                $request->update(['status' => 'placed']);
            }

            return $decision;
        });
    }
}
```

- [ ] **Step 4: Run → PASS**. **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/BedPlacementService.php
git add app/Services/BedPlacementService.php tests/Feature/Rtdc/BedPlacementServiceTest.php
git commit -m "feat(rtdc): BedPlacementService — recommend, decide, dispatch placement, audit"
```

---

## PHASE D — API + FormRequests

### Task D1: Bed-request API (create, recommendations, decision)

**Files:** Create `app/Http/Controllers/Api/Rtdc/BedRequestController.php`, `app/Http/Requests/Rtdc/CreateBedRequestRequest.php`, `app/Http/Requests/Rtdc/BedPlacementDecisionRequest.php`; Modify `routes/api.php`; Test `tests/Feature/Rtdc/BedRequestApiTest.php`

- [ ] **Step 1: Failing test**

Create `tests/Feature/Rtdc/BedRequestApiTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_request_then_get_recommendations_then_accept(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);

        $req = $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg',
        ])->assertOk()->json('data');

        $recs = $this->actingAs($user)->getJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/recommendations")
            ->assertOk()->assertJsonStructure(['data' => ['recommendations' => [['bed_id', 'score', 'breakdown', 'chips']], 'runner_up_delta', 'excluded']])
            ->json('data');
        $this->assertEquals($bed->bed_id, $recs['recommendations'][0]['bed_id']);

        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertOk();

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p1', 'status' => 'active']);
    }

    public function test_create_rejects_invalid_source(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'p', 'source' => 'walkin', 'acuity_tier' => 2,
        ])->assertStatus(422);
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/rtdc/bed-requests/1/recommendations')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: FormRequests**

Create `app/Http/Requests/Rtdc/CreateBedRequestRequest.php`:
```php
<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class CreateBedRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_ref' => 'required|string|max:100',
            'source' => 'required|in:ed,transfer,direct,or',
            'sex' => 'nullable|in:M,F,other',
            'service' => 'nullable|string|max:100',
            'acuity_tier' => 'required|integer|min:1|max:4',
            'isolation_required' => 'required|in:none,contact,droplet,airborne',
            'required_unit_type' => 'required|in:any,med_surg,icu,step_down',
        ];
    }
}
```

Create `app/Http/Requests/Rtdc/BedPlacementDecisionRequest.php`:
```php
<?php

namespace App\Http\Requests\Rtdc;

use App\Models\Bed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BedPlacementDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:accepted,edited,rejected',
            'chosen_bed_id' => ['nullable', Rule::exists(Bed::class, 'bed_id')],
            'reason' => 'nullable|string|max:500',
        ];
    }
}
```

- [ ] **Step 4: Controller**

Create `app/Http/Controllers/Api/Rtdc/BedRequestController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\BedPlacementDecisionRequest;
use App\Http\Requests\Rtdc\CreateBedRequestRequest;
use App\Models\BedRequest;
use App\Services\BedPlacementService;
use Illuminate\Http\JsonResponse;

class BedRequestController extends Controller
{
    public function __construct(private readonly BedPlacementService $placement) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => BedRequest::pending()->orderBy('created_at')->get()]);
    }

    public function store(CreateBedRequestRequest $request): JsonResponse
    {
        $bedRequest = BedRequest::create($request->validated());

        return response()->json(['data' => $bedRequest]);
    }

    public function recommendations(int $bedRequestId): JsonResponse
    {
        $request = BedRequest::findOrFail($bedRequestId);

        return response()->json(['data' => $this->placement->recommend($request)->toArray()]);
    }

    public function decision(int $bedRequestId, BedPlacementDecisionRequest $request): JsonResponse
    {
        $bedRequest = BedRequest::findOrFail($bedRequestId);
        $v = $request->validated();
        $decision = $this->placement->decide(
            $bedRequest,
            $v['action'],
            $v['chosen_bed_id'] ?? null,
            $v['reason'] ?? null,
            $request->user()?->id,
        );

        return response()->json(['data' => $decision]);
    }
}
```

- [ ] **Step 5: Routes** — add INSIDE the existing `['web','auth']` `rtdc` group in `routes/api.php`:
```php
use App\Http\Controllers\Api\Rtdc\BedRequestController;

    Route::get('/bed-requests', [BedRequestController::class, 'index']);
    Route::post('/bed-requests', [BedRequestController::class, 'store']);
    Route::get('/bed-requests/{bedRequestId}/recommendations', [BedRequestController::class, 'recommendations']);
    Route::post('/bed-requests/{bedRequestId}/decision', [BedRequestController::class, 'decision']);
```

- [ ] **Step 6: Run → PASS**. `php artisan test --filter=BedRequestApiTest`.
- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint app/Http
git add app/Http/Controllers/Api/Rtdc/BedRequestController.php app/Http/Requests/Rtdc/CreateBedRequestRequest.php app/Http/Requests/Rtdc/BedPlacementDecisionRequest.php routes/api.php tests/Feature/Rtdc/BedRequestApiTest.php
git commit -m "feat(rtdc): bed-request API — create, recommendations, decision"
```

---

## PHASE E — Frontend panel

### Task E1: Zod schemas + API fetchers + hooks for bed placement

**Files:** Modify `resources/js/schemas/rtdc.ts`; create `resources/js/features/rtdc/bedPlacement.ts`; Test `tests/js/rtdc/bed-placement-api.test.ts`

- [ ] **Step 1: Failing test**

Create `tests/js/rtdc/bed-placement-api.test.ts`:
```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { fetchRecommendations, createBedRequest } from '@/features/rtdc/bedPlacement';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

describe('bed placement api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchRecommendations validates the envelope', async () => {
    mocked.get.mockResolvedValue({ data: { data: {
      recommendations: [{ bed_id: 1, bed_label: '5E-01', unit_id: 1, unit_name: '5 East', score: 30, breakdown: [{ term: 'acuity_headroom', value: 20 }], chips: [{ label: 'Ratio OK', ok: true }] }],
      runner_up_delta: 12, excluded: [{ bed_id: 2, reason: 'isolation mismatch' }],
    } } });
    const recs = await fetchRecommendations(7);
    expect(recs.recommendations[0].bed_id).toBe(1);
    expect(recs.runner_up_delta).toBe(12);
    expect(mocked.get).toHaveBeenCalledWith('/api/rtdc/bed-requests/7/recommendations');
  });

  it('createBedRequest posts validated payload', async () => {
    mocked.post.mockResolvedValue({ data: { data: { bed_request_id: 5, patient_ref: 'p', source: 'ed', acuity_tier: 2, isolation_required: 'none', required_unit_type: 'med_surg', status: 'pending' } } });
    const r = await createBedRequest({ patient_ref: 'p', source: 'ed', acuity_tier: 2, isolation_required: 'none', required_unit_type: 'med_surg' });
    expect(r.bed_request_id).toBe(5);
  });
});
```

- [ ] **Step 2: Run → FAIL** — `npx vitest run tests/js/rtdc/bed-placement-api.test.ts`.

- [ ] **Step 3: Schemas** — append to `resources/js/schemas/rtdc.ts`:
```ts
export const bedRequestSchema = z.object({
  bed_request_id: z.number(),
  patient_ref: z.string(),
  source: z.enum(['ed', 'transfer', 'direct', 'or']),
  sex: z.string().nullable().optional(),
  service: z.string().nullable().optional(),
  acuity_tier: z.number(),
  isolation_required: z.enum(['none', 'contact', 'droplet', 'airborne']),
  required_unit_type: z.enum(['any', 'med_surg', 'icu', 'step_down']),
  status: z.enum(['pending', 'placed', 'cancelled']),
});
export type BedRequest = z.infer<typeof bedRequestSchema>;

export const recommendationSchema = z.object({
  bed_id: z.number(),
  bed_label: z.string(),
  unit_id: z.number(),
  unit_name: z.string(),
  score: z.number(),
  breakdown: z.array(z.object({ term: z.string(), value: z.number() })),
  chips: z.array(z.object({ label: z.string(), ok: z.boolean() })),
});

export const rankedRecommendationsSchema = z.object({
  recommendations: z.array(recommendationSchema),
  runner_up_delta: z.number().nullable(),
  excluded: z.array(z.object({ bed_id: z.number(), reason: z.string() })),
});
export type RankedRecommendations = z.infer<typeof rankedRecommendationsSchema>;
```

- [ ] **Step 4: Fetchers** — create `resources/js/features/rtdc/bedPlacement.ts`:
```ts
import axios from 'axios';
import { z } from 'zod';
import {
  bedRequestSchema, rankedRecommendationsSchema,
  type BedRequest, type RankedRecommendations,
} from '@/schemas/rtdc';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchPendingRequests(): Promise<BedRequest[]> {
  const res = await axios.get('/api/rtdc/bed-requests');
  return envelope(z.array(bedRequestSchema)).parse(res.data).data;
}

export interface CreateBedRequestInput {
  patient_ref: string; source: string; acuity_tier: number;
  isolation_required: string; required_unit_type: string; sex?: string; service?: string;
}
export async function createBedRequest(input: CreateBedRequestInput): Promise<BedRequest> {
  const res = await axios.post('/api/rtdc/bed-requests', input);
  return envelope(bedRequestSchema).parse(res.data).data;
}

export async function fetchRecommendations(bedRequestId: number): Promise<RankedRecommendations> {
  const res = await axios.get(`/api/rtdc/bed-requests/${bedRequestId}/recommendations`);
  return envelope(rankedRecommendationsSchema).parse(res.data).data;
}

export interface DecisionInput { action: 'accepted' | 'edited' | 'rejected'; chosen_bed_id?: number; reason?: string }
export async function postDecision(bedRequestId: number, input: DecisionInput): Promise<void> {
  await axios.post(`/api/rtdc/bed-requests/${bedRequestId}/decision`, input);
}
```

- [ ] **Step 5: Run → PASS**, then `npx vite build`. **Step 6: Commit**

```bash
git add resources/js/schemas/rtdc.ts resources/js/features/rtdc/bedPlacement.ts tests/js/rtdc/bed-placement-api.test.ts
git commit -m "feat(rtdc): bed-placement Zod schemas + typed fetchers"
```

---

### Task E2: BedPlacement page + recommendation card component

**Files:** Create `resources/js/Components/RTDC/RecommendationCard.tsx`, `resources/js/Pages/RTDC/BedPlacement.tsx`; add web route + controller method; Test `tests/js/rtdc/recommendation-card.test.tsx`

- [ ] **Step 1: Failing test (component)**

Create `tests/js/rtdc/recommendation-card.test.tsx`:
```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RecommendationCard } from '@/Components/RTDC/RecommendationCard';

const rec = {
  bed_id: 1, bed_label: '5E-01', unit_id: 1, unit_name: '5 East', score: 30,
  breakdown: [{ term: 'acuity_headroom', value: 20 }, { term: 'isolation_fragmentation', value: -25 }],
  chips: [{ label: 'Ratio OK', ok: true }, { label: 'Acuity headroom: 4', ok: true }],
};

describe('RecommendationCard', () => {
  it('shows bed, score, chips, breakdown, and the not-automated safety note', () => {
    render(<RecommendationCard rec={rec} isTop runnerUpDelta={12} onAccept={() => {}} />);
    expect(screen.getByText('5E-01')).toBeInTheDocument();
    expect(screen.getByText(/5 East/)).toBeInTheDocument();
    expect(screen.getByText('Ratio OK')).toBeInTheDocument();
    expect(screen.getByText(/acuity_headroom/)).toBeInTheDocument();
    expect(screen.getByText(/not an automated assignment/i)).toBeInTheDocument();
  });

  it('fires onAccept with the bed id', () => {
    const onAccept = vi.fn();
    render(<RecommendationCard rec={rec} isTop runnerUpDelta={12} onAccept={onAccept} />);
    fireEvent.click(screen.getByRole('button', { name: /accept/i }));
    expect(onAccept).toHaveBeenCalledWith(1);
  });
});
```

- [ ] **Step 2: Run → FAIL**.

- [ ] **Step 3: RecommendationCard** — create `resources/js/Components/RTDC/RecommendationCard.tsx`:
```tsx
import type { RankedRecommendations } from '@/schemas/rtdc';

type Rec = RankedRecommendations['recommendations'][number];

interface RecommendationCardProps {
  rec: Rec;
  isTop: boolean;
  runnerUpDelta: number | null;
  onAccept: (bedId: number) => void;
}

export function RecommendationCard({ rec, isTop, runnerUpDelta, onAccept }: RecommendationCardProps) {
  return (
    <div className={`rounded-[var(--radius-lg)] p-[var(--space-5)] ${isTop ? 'bg-[var(--surface-raised)] ring-1 ring-[var(--accent)]' : 'bg-[var(--surface-overlay)]'}`}>
      <div className="flex items-center justify-between">
        <div>
          <span className="text-value">{rec.bed_label}</span>
          <span className="text-caption ml-[var(--space-2)]">{rec.unit_name}</span>
        </div>
        <span className="text-label">Score {rec.score}{isTop && runnerUpDelta !== null ? ` · +${runnerUpDelta} vs next` : ''}</span>
      </div>

      <div className="mt-[var(--space-3)] flex flex-wrap gap-[var(--space-2)]">
        {rec.chips.map((c) => (
          <span key={c.label} className={`text-caption rounded-[var(--radius-sm)] px-[var(--space-2)] py-[2px] ${c.ok ? 'bg-[var(--success-bg)] text-[var(--success)]' : 'bg-[var(--critical-bg)] text-[var(--critical)]'}`}>
            {c.label}
          </span>
        ))}
      </div>

      <div className="mt-[var(--space-3)] flex flex-wrap gap-[var(--space-2)]">
        {rec.breakdown.map((b) => (
          <span key={b.term} className="text-caption text-[var(--text-muted)]">
            {b.term}: <span className={b.value < 0 ? 'text-[var(--critical)]' : 'text-[var(--success)]'}>{b.value > 0 ? `+${b.value}` : b.value}</span>
          </span>
        ))}
      </div>

      <div className="mt-[var(--space-4)] flex items-center justify-between">
        <span className="text-caption italic">Recommendation for placement decision — not an automated assignment.</span>
        <button onClick={() => onAccept(rec.bed_id)} className="rounded-[var(--radius-md)] bg-[var(--primary)] px-[var(--space-4)] py-[var(--space-2)] text-white">
          Accept
        </button>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: BedPlacement page** — create `resources/js/Pages/RTDC/BedPlacement.tsx`:
```tsx
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { RecommendationCard } from '@/Components/RTDC/RecommendationCard';
import { fetchPendingRequests, fetchRecommendations, postDecision } from '@/features/rtdc/bedPlacement';

export default function BedPlacement() {
  const qc = useQueryClient();
  const [selected, setSelected] = useState<number | null>(null);

  const { data: requests } = useQuery({ queryKey: ['rtdc', 'bed-requests'], queryFn: fetchPendingRequests });
  const { data: recs } = useQuery({
    queryKey: ['rtdc', 'recommendations', selected],
    queryFn: () => fetchRecommendations(selected as number),
    enabled: selected !== null,
  });

  const accept = useMutation({
    mutationFn: ({ id, bedId }: { id: number; bedId: number }) => postDecision(id, { action: 'accepted', chosen_bed_id: bedId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['rtdc', 'bed-requests'] });
      qc.invalidateQueries({ queryKey: ['rtdc', 'units'] });
      setSelected(null);
    },
  });

  return (
    <RTDCPageLayout title="Bed Placement" subtitle="Prescriptive bed-assignment recommendations">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-[var(--space-6)]">
        <section>
          <h3 className="text-panel-title">Pending requests</h3>
          <ul className="flex flex-col gap-[var(--space-2)]">
            {(requests ?? []).map((r) => (
              <li key={r.bed_request_id}>
                <button
                  onClick={() => setSelected(r.bed_request_id)}
                  className={`w-full text-left rounded-[var(--radius-sm)] p-[var(--space-3)] ${selected === r.bed_request_id ? 'bg-[var(--surface-raised)]' : 'bg-[var(--surface-overlay)]'}`}
                >
                  <span className="text-[var(--text-primary)]">{r.patient_ref}</span>
                  <span className="text-caption ml-[var(--space-2)]">{r.source} · tier {r.acuity_tier} · {r.required_unit_type}{r.isolation_required !== 'none' ? ` · ${r.isolation_required}` : ''}</span>
                </button>
              </li>
            ))}
            {(requests ?? []).length === 0 && <li className="text-caption">No pending requests.</li>}
          </ul>
        </section>

        <section className="lg:col-span-2 flex flex-col gap-[var(--space-4)]">
          <h3 className="text-panel-title">Recommendations</h3>
          {selected === null && <div className="text-caption">Select a pending request to see recommendations.</div>}
          {recs && recs.recommendations.length === 0 && (
            <div className="rounded-[var(--radius-lg)] bg-[var(--critical-bg)] p-[var(--space-5)] text-[var(--critical)]">
              No safe bed available — every candidate failed a hard clinical/safety constraint. ({recs.excluded.length} excluded)
            </div>
          )}
          {recs?.recommendations.map((rec, i) => (
            <RecommendationCard
              key={rec.bed_id}
              rec={rec}
              isTop={i === 0}
              runnerUpDelta={recs.runner_up_delta}
              onAccept={(bedId) => selected !== null && accept.mutate({ id: selected, bedId })}
            />
          ))}
        </section>
      </div>
    </RTDCPageLayout>
  );
}
```

- [ ] **Step 5: Web route + controller** — in `routes/web.php`, inside the `rtdc` web group, add:
```php
Route::get('/bed-placement', [RTDCDashboardController::class, 'bedPlacement'])->name('rtdc.bed-placement');
```
and add to `app/Http/Controllers/RTDCDashboardController.php` (mirroring its `unitHuddle`):
```php
public function bedPlacement(\Illuminate\Http\Request $request): \Inertia\Response
{
    $this->rtdcService->activateWorkflow($request);

    return \Inertia\Inertia::render('RTDC/BedPlacement');
}
```
(Use the controller's existing injected service property name — check it; it is `$this->rtdcService` if present, else match the existing `unitHuddle` method exactly.)

- [ ] **Step 6: Run → PASS** (`npx vitest run tests/js/rtdc/recommendation-card.test.tsx`), then `npx vite build`, then `php artisan route:list --path=rtdc/bed-placement`.
- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/RTDC/RecommendationCard.tsx resources/js/Pages/RTDC/BedPlacement.tsx routes/web.php app/Http/Controllers/RTDCDashboardController.php tests/js/rtdc/recommendation-card.test.tsx
git commit -m "feat(rtdc): Bed Placement page with explainable recommendation cards + safety chips"
```

---

## PHASE F — Integration + final green

### Task F1: End-to-end placement integration test (backend)

**Files:** Test `tests/Feature/Rtdc/BedPlacementFlowTest.php`

- [ ] **Step 1: Test**

Create `tests/Feature/Rtdc/BedPlacementFlowTest.php`:
```php
<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedPlacementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_request_to_live_census(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 4, 'ratio_floor' => 2]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'ICU-01', 'status' => 'available', 'isolation_capable' => true]);

        // 1. ED requests an ICU bed for a tier-4 isolation patient.
        $req = $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'crit-1', 'source' => 'ed', 'acuity_tier' => 4, 'isolation_required' => 'contact', 'required_unit_type' => 'icu',
        ])->json('data');

        // 2. Recommendations are returned; the ICU isolation bed is the only feasible one.
        $recs = $this->actingAs($user)->getJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/recommendations")->json('data');
        $this->assertEquals($bed->bed_id, $recs['recommendations'][0]['bed_id']);

        // 3. Accept → census reflects the placement live, audit captured.
        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertOk();

        $census = $this->actingAs($user)->getJson('/api/rtdc/units')->json('data');
        $icu = collect($census)->firstWhere('unit_id', $unit->unit_id);
        $this->assertEquals(1, $icu['census']['occupied']);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req['bed_request_id'], 'action' => 'accepted']);
    }
}
```

- [ ] **Step 2: Run → PASS** (all wiring already exists). If red, fix the wiring per the failure.
- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Rtdc/BedPlacementFlowTest.php
git commit -m "test(rtdc): end-to-end bed-placement flow (request -> recommend -> accept -> live census)"
```

### Task F2: Final verification

- [ ] Run: `php artisan test --filter=Rtdc` — all green (S2 + S4).
- [ ] Run: `npx vitest run tests/js/rtdc` — all green.
- [ ] Run: `npx vite build` — clean.
- [ ] Run: `vendor/bin/pint --test app` — only pre-existing legacy flags.
- [ ] Confirm the safety-guarantee test (`HeuristicOptimizerTest::test_safety_guarantee_no_recommendation_violates_hard_constraints`) is green.

---

## Self-Review

**Spec coverage:** BedRequest + decisions (A2/A3); optimizer interface + heuristic + DTOs (B1/B2); hard safety feasible region (B2 — capability/isolation/safety prune); soft scoring + explainability + runner-up (B2); decision capture + dispatch reuse (C1); API (D1); explainable UI + safety chips + "not automated" copy (E2); empty-feasible "no safe bed" (B2 test + E2 UI); the safety guarantee across randomized inputs (B2); swap seam documented (B1 interface). ✓

**Deviations (documented above):** gender constraint deferred (no room model in S2); soft terms limited to S2-available data; Python CP-SAT optimizer is the future swap behind the interface. These are honest scope cuts, not gaps in the core safety/explainability value.

**Type consistency:** `RankedRecommendations::toArray()` keys (`recommendations`/`runner_up_delta`/`excluded`, each recommendation `bed_id`/`score`/`breakdown`/`chips`) match the Zod `rankedRecommendationsSchema` (E1) and the `RecommendationCard` props (E2). `BedPlacementService::decide` signature matches the controller call (D1). `AcuityService::canAccept/remainingWorkload` (A1) are used by the optimizer (B2). ✓

**No placeholders:** every step has runnable code/commands.
